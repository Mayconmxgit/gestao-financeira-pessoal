<?php

namespace App\Modulos\Assinaturas\Servicos;

use App\Modulos\Assinaturas\Dominio\SituacoesAssinatura;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ServicoAdministracaoAssinaturas
{
    public function __construct(private ServicoAssinaturas $assinaturas) {}

    public function resumo(): array
    {
        $situacoes = DB::table('assinaturas')->selectRaw('situacao, COUNT(*) as total')->groupBy('situacao')
            ->pluck('total', 'situacao')->map(fn ($total) => (int) $total)->all();

        return [
            'planos_ativos' => DB::table('planos_assinatura')->where('ativo', true)->count(),
            'assinaturas_ativas' => ($situacoes['ativa'] ?? 0) + ($situacoes['periodo_graca'] ?? 0),
            'assinaturas_pendentes' => $situacoes['pendente'] ?? 0,
            'assinaturas_inadimplentes' => $situacoes['inadimplente'] ?? 0,
            'receita_mensal_estimada' => $this->receitaMensalEstimada(),
        ];
    }

    public function listarPlanos(): array
    {
        return DB::table('planos_assinatura')->orderBy('valor')->get()
            ->map(fn (object $plano) => $this->assinaturas->formatarPlano($plano))->all();
    }

    public function criarPlano(int $administrador, array $dados): array
    {
        $uuid = (string) Str::uuid();
        DB::table('planos_assinatura')->insert([
            'uuid' => $uuid, ...$this->dadosPlano($dados), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->auditar($administrador, 'plano_criado', 'plano', $uuid, ['nome' => $dados['nome']]);

        return $this->assinaturas->formatarPlano(DB::table('planos_assinatura')->where('uuid', $uuid)->first());
    }

    public function atualizarPlano(int $administrador, string $uuid, array $dados): array
    {
        $plano = DB::table('planos_assinatura')->where('uuid', $uuid)->first();
        abort_if($plano === null, 404);
        DB::table('planos_assinatura')->where('id', $plano->id)->update([
            ...$this->dadosPlano($dados), 'updated_at' => now(),
        ]);
        $this->auditar($administrador, 'plano_atualizado', 'plano', $uuid, ['nome' => $dados['nome']]);

        return $this->assinaturas->formatarPlano(DB::table('planos_assinatura')->where('id', $plano->id)->first());
    }

    public function listarAssinaturas(?int $apos, int $limite, ?string $situacao): array
    {
        $consulta = $this->assinaturas->consultaAssinaturas()
            ->when($apos !== null, fn ($query) => $query->where('assinatura.id', '<', $apos))
            ->when($situacao !== null, fn ($query) => $query->where('assinatura.situacao', $situacao))
            ->orderByDesc('assinatura.id')->limit($limite + 1)->get();
        $temProxima = $consulta->count() > $limite;
        $pagina = $consulta->take($limite);

        return [
            'dados' => $pagina->map(fn (object $item) => $this->assinaturas->formatarAssinatura($item))->values(),
            'proximo_cursor' => $temProxima ? $pagina->last()->id : null,
        ];
    }

    public function listarEventos(?int $apos, int $limite): array
    {
        $eventos = DB::table('eventos_webhook_assinatura')
            ->when($apos !== null, fn ($query) => $query->where('id', '<', $apos))
            ->orderByDesc('id')->limit($limite + 1)->get();
        $temProxima = $eventos->count() > $limite;
        $pagina = $eventos->take($limite);

        return ['dados' => $pagina->map(fn (object $evento) => [
            'id' => $evento->uuid, 'tipo' => $evento->tipo, 'acao' => $evento->acao,
            'situacao' => $evento->situacao, 'codigo_erro' => $evento->codigo_erro,
            'recebido_em' => $evento->created_at, 'processado_em' => $evento->processado_em,
        ])->values(), 'proximo_cursor' => $temProxima ? $pagina->last()->id : null];
    }

    public function reconciliar(int $administrador, string $uuid): array
    {
        $assinatura = DB::table('assinaturas')->where('uuid', $uuid)->first();
        abort_if($assinatura === null, 404);
        abort_if(! is_string($assinatura->identificador_externo), 409);
        $resultado = $this->assinaturas->sincronizarPorIdentificador($assinatura->identificador_externo);
        $this->auditar($administrador, 'assinatura_reconciliada', 'assinatura', $uuid, $resultado ?? []);

        return $resultado ?? abort(404);
    }

    public function concederCortesia(int $administrador, object $usuario, object $plano, array $dados): array
    {
        $uuid = (string) Str::uuid();
        DB::transaction(function () use ($administrador, $usuario, $plano, $dados, $uuid): void {
            DB::table('usuarios')->where('id', $usuario->id)->lockForUpdate()->first();
            $substituida = $this->substituirPlanoGratuito($usuario->id);
            $this->inserirCortesia($uuid, $usuario->id, $plano, $dados['fim']);
            $detalhes = [
                'usuario_id' => $usuario->id, 'plano_id' => $plano->uuid, 'fim' => $dados['fim'],
                'motivo' => $dados['motivo'],
            ];
            if ($substituida !== null) {
                $detalhes['assinatura_substituida_uuid'] = $substituida;
            }
            $this->auditar($administrador, 'cortesia_concedida', 'assinatura', $uuid, $detalhes);
        });

        return $this->assinaturas->formatarAssinatura($this->assinaturas->consultaAssinaturas()->where('assinatura.uuid', $uuid)->first());
    }

    private function substituirPlanoGratuito(int $usuario): ?string
    {
        $assinaturas = DB::table('assinaturas')->where('usuario_id', $usuario)
            ->whereIn('situacao', SituacoesAssinatura::EM_ANDAMENTO)->lockForUpdate()->get();
        abort_if($assinaturas->count() > 1, 409, 'ASSINATURA_EM_ANDAMENTO');
        $assinatura = $assinaturas->first();
        if ($assinatura === null) {
            return null;
        }
        abort_unless($assinatura->provedor === 'gratuito' && $assinatura->situacao === 'ativa', 409, 'ASSINATURA_EM_ANDAMENTO');
        DB::table('assinaturas')->where('id', $assinatura->id)->update([
            'situacao' => 'cancelada', 'cancelada_em' => now(), 'updated_at' => now(),
        ]);

        return $assinatura->uuid;
    }

    private function inserirCortesia(string $uuid, int $usuario, object $plano, string $fim): void
    {
        DB::table('assinaturas')->insert([
            'uuid' => $uuid, 'usuario_id' => $usuario, 'plano_id' => $plano->id,
            'provedor' => 'cortesia', 'referencia_externa' => (string) Str::uuid(), 'situacao' => 'ativa',
            'valor_contratado' => '0.00', 'moeda' => $plano->moeda, 'periodicidade' => $plano->periodicidade,
            'intervalo' => $plano->intervalo, 'periodo_acesso_fim' => $fim,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function dadosPlano(array $dados): array
    {
        return [
            'nome' => trim($dados['nome']), 'descricao' => isset($dados['descricao']) ? trim($dados['descricao']) : null,
            'valor' => $dados['valor'], 'moeda' => 'BRL', 'periodicidade' => $dados['periodicidade'],
            'intervalo' => 1, 'recursos' => json_encode(array_values($dados['recursos']), JSON_THROW_ON_ERROR),
            'ativo' => $dados['ativo'],
        ];
    }

    private function receitaMensalEstimada(): string
    {
        $mensal = DB::table('assinaturas')->whereIn('situacao', ['ativa', 'periodo_graca'])
            ->where('periodicidade', 'mensal')->sum('valor_contratado');
        $anual = DB::table('assinaturas')->whereIn('situacao', ['ativa', 'periodo_graca'])
            ->where('periodicidade', 'anual')->sum('valor_contratado');

        return number_format((float) $mensal + ((float) $anual / 12), 2, '.', '');
    }

    private function auditar(int $administrador, string $acao, string $entidade, string $uuid, array $detalhes): void
    {
        DB::table('auditoria_assinaturas')->insert([
            'administrador_id' => $administrador, 'acao' => $acao, 'entidade' => $entidade,
            'entidade_uuid' => $uuid, 'detalhes' => json_encode($detalhes, JSON_THROW_ON_ERROR), 'criado_em' => now(),
        ]);
    }
}
