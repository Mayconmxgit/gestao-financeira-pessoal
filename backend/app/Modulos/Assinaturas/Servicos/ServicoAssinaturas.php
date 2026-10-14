<?php

namespace App\Modulos\Assinaturas\Servicos;

use App\Modulos\Administracao\Servicos\RegistradorCicloAssinatura;
use App\Modulos\Assinaturas\Dominio\SituacoesAssinatura;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ServicoAssinaturas
{
    public function __construct(
        private ClienteMercadoPagoAssinaturas $mercadoPago,
        private RegistradorCicloAssinatura $ciclo,
    ) {}

    public function listarPlanosAtivos(): array
    {
        return DB::table('planos_assinatura')->where('ativo', true)->orderBy('valor')->get()
            ->map(fn (object $plano) => $this->plano($plano))->all();
    }

    public function assinaturaAtual(int $usuario): ?array
    {
        $assinatura = $this->consultaAssinaturas()->where('assinatura.usuario_id', $usuario)
            ->orderByDesc('assinatura.id')->first();

        return $assinatura ? $this->assinatura($assinatura) : null;
    }

    public function criarCheckout(object $usuario, string $planoUuid): array
    {
        $plano = DB::table('planos_assinatura')->where('uuid', $planoUuid)->where('ativo', true)->first();
        abort_if($plano === null, 404);
        abort_if($this->possuiAssinaturaEmAndamento((int) $usuario->id), 409);
        if ((float) $plano->valor === 0.0) {
            return $this->ativarPlanoGratuito((int) $usuario->id, $plano);
        }
        abort_unless($this->mercadoPago->disponivel(), 503);
        $dados = $this->novaAssinatura((int) $usuario->id, $plano);
        $assinaturaId = DB::transaction(function () use ($dados): int {
            $id = DB::table('assinaturas')->insertGetId($dados);
            $this->ciclo->registrar($id, null, 'criando', 'checkout');

            return $id;
        });
        try {
            $externa = $this->mercadoPago->criar($dados, $plano, $usuario);
            $novaSituacao = $this->situacao((string) $externa['status']);
            DB::transaction(function () use ($dados, $externa, $novaSituacao, $assinaturaId): void {
                DB::table('assinaturas')->where('uuid', $dados['uuid'])->update([
                    'identificador_externo' => $externa['id'], 'url_checkout' => $externa['init_point'],
                    'situacao' => $novaSituacao, 'updated_at' => now(),
                ]);
                $this->ciclo->registrar($assinaturaId, 'criando', $novaSituacao, 'checkout');
            });
        } catch (RuntimeException $excecao) {
            DB::transaction(function () use ($dados, $assinaturaId): void {
                DB::table('assinaturas')->where('uuid', $dados['uuid'])->update(['situacao' => 'falha', 'updated_at' => now()]);
                $this->ciclo->registrar($assinaturaId, 'criando', 'falha', 'checkout');
            });
            throw $excecao;
        }

        return ['assinatura_id' => $dados['uuid'], 'url_checkout' => $externa['init_point']];
    }

    private function ativarPlanoGratuito(int $usuario, object $plano): array
    {
        return DB::transaction(function () use ($usuario, $plano): array {
            DB::table('usuarios')->where('id', $usuario)->lockForUpdate()->first();
            abort_if($this->possuiAssinaturaEmAndamento($usuario), 409);
            $dados = $this->novaAssinatura($usuario, $plano);
            $dados['provedor'] = 'gratuito';
            $dados['situacao'] = 'ativa';
            $assinaturaId = DB::table('assinaturas')->insertGetId($dados);
            $this->ciclo->registrar($assinaturaId, null, 'ativa', 'plano_gratuito');

            return ['assinatura_id' => $dados['uuid'], 'url_checkout' => null, 'gratuito' => true];
        });
    }

    public function sincronizarPorIdentificador(string $identificador): ?array
    {
        $externa = $this->mercadoPago->consultar($identificador);
        $assinatura = DB::table('assinaturas')->where('identificador_externo', $externa['id'])->first();
        if ($assinatura === null && isset($externa['external_reference'])) {
            $assinatura = DB::table('assinaturas')->where('referencia_externa', (string) $externa['external_reference'])->first();
        }
        if ($assinatura === null) {
            return null;
        }
        $alteracoes = $this->dadosSincronizados($externa);
        DB::transaction(function () use ($assinatura, $alteracoes): void {
            $atual = DB::table('assinaturas')->where('id', $assinatura->id)->lockForUpdate()->first();
            if ($atual === null) {
                return;
            }
            DB::table('assinaturas')->where('id', $assinatura->id)->update($alteracoes);
            $this->ciclo->registrar($assinatura->id, $atual->situacao, $alteracoes['situacao'], 'webhook');
        });

        return ['uuid' => $assinatura->uuid, 'situacao' => $alteracoes['situacao']];
    }

    public function formatarPlano(object $plano): array
    {
        return $this->plano($plano);
    }

    public function formatarAssinatura(object $assinatura): array
    {
        return $this->assinatura($assinatura);
    }

    public function consultaAssinaturas()
    {
        return DB::table('assinaturas as assinatura')
            ->join('usuarios as usuario', 'usuario.id', '=', 'assinatura.usuario_id')
            ->join('planos_assinatura as plano', 'plano.id', '=', 'assinatura.plano_id')
            ->select(
                'assinatura.*', 'usuario.nome as usuario_nome', 'usuario.email as usuario_email',
                'plano.nome as plano_nome', 'plano.recursos as plano_recursos'
            );
    }

    private function possuiAssinaturaEmAndamento(int $usuario): bool
    {
        return DB::table('assinaturas')->where('usuario_id', $usuario)
            ->whereIn('situacao', SituacoesAssinatura::EM_ANDAMENTO)->exists();
    }

    private function novaAssinatura(int $usuario, object $plano): array
    {
        return [
            'uuid' => (string) Str::uuid(), 'usuario_id' => $usuario, 'plano_id' => $plano->id,
            'provedor' => 'mercado_pago', 'referencia_externa' => (string) Str::uuid(),
            'situacao' => 'criando', 'valor_contratado' => $plano->valor, 'moeda' => $plano->moeda,
            'periodicidade' => $plano->periodicidade, 'intervalo' => $plano->intervalo,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function dadosSincronizados(array $externa): array
    {
        $situacao = $this->situacao((string) $externa['status']);

        return [
            'identificador_externo' => $externa['id'], 'situacao' => $situacao,
            'proxima_cobranca_em' => $externa['next_payment_date'] ?? null,
            'cancelada_em' => $situacao === 'cancelada' ? now() : null, 'updated_at' => now(),
        ];
    }

    private function situacao(string $externa): string
    {
        return match ($externa) {
            'authorized' => 'ativa', 'paused' => 'pausada', 'cancelled', 'canceled' => 'cancelada',
            'pending' => 'pendente', default => 'pendente',
        };
    }

    private function plano(object $plano): array
    {
        return [
            'id' => $plano->uuid, 'nome' => $plano->nome, 'descricao' => $plano->descricao,
            'valor' => number_format((float) $plano->valor, 2, '.', ''), 'moeda' => $plano->moeda,
            'periodicidade' => $plano->periodicidade, 'intervalo' => (int) $plano->intervalo,
            'recursos' => json_decode($plano->recursos, true, 512, JSON_THROW_ON_ERROR), 'ativo' => (bool) $plano->ativo,
        ];
    }

    private function assinatura(object $assinatura): array
    {
        return [
            'id' => $assinatura->uuid, 'usuario_nome' => $assinatura->usuario_nome ?? null,
            'usuario_email' => $assinatura->usuario_email ?? null, 'plano_nome' => $assinatura->plano_nome ?? null,
            'provedor' => $assinatura->provedor, 'situacao' => $assinatura->situacao,
            'valor' => number_format((float) $assinatura->valor_contratado, 2, '.', ''),
            'moeda' => $assinatura->moeda, 'periodicidade' => $assinatura->periodicidade,
            'recursos' => json_decode($assinatura->plano_recursos ?? '[]', true, 512, JSON_THROW_ON_ERROR),
            'proxima_cobranca_em' => $assinatura->proxima_cobranca_em,
            'periodo_acesso_fim' => $assinatura->periodo_acesso_fim, 'criada_em' => $assinatura->created_at,
        ];
    }
}
