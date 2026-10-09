<?php

namespace App\Modulos\Financeiro\Planejamento;

use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use App\Modulos\Financeiro\Suporte\IdentidadeTransacao as Identidade;
use App\Modulos\Financeiro\Suporte\IdentificadorPublico;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ServicoAportesMetas
{
    /** @param array{conta_id: int, valor: string, data: string, observacao?: string|null} $dados */
    public function registrar(int $usuario, int $metaId, array $dados): int
    {
        return DB::transaction(function () use ($usuario, $metaId, $dados): int {
            $meta = $this->metaAtiva($usuario, $metaId);
            $conta = $this->contaValida($usuario, $dados['conta_id']);
            $valor = Entrada::moeda($dados['valor']);
            $transacao = $this->criarTransacao($usuario, $meta, $conta, $valor, $dados['data']);
            $aporte = $this->criarAporte($usuario, $metaId, $conta->id, $transacao, $valor, $dados);
            $this->atualizarSituacao($usuario, $meta, $metaId);
            Entrada::auditar($usuario, 'aporte_meta_registrado', 'metas_financeiras', $metaId, [
                'aporte_id' => $aporte, 'conta_id' => $conta->id, 'transacao_id' => $transacao,
            ]);

            return $aporte;
        });
    }

    private function atualizarSituacao(int $usuario, object $meta, int $metaId): void
    {
        $total = Entrada::moeda((string) DB::table('aportes_metas')
            ->where('usuario_id', $usuario)->where('meta_id', $metaId)->sum('valor'));
        $situacao = bccomp($total, (string) $meta->valor_objetivo, 2) >= 0 ? 'concluida' : 'ativa';
        DB::table('metas_financeiras')->where('usuario_id', $usuario)->where('id', $metaId)
            ->update(['situacao' => $situacao, 'updated_at' => now()]);
    }

    private function metaAtiva(int $usuario, int $metaId): object
    {
        $meta = DB::table('metas_financeiras')->where('usuario_id', $usuario)
            ->where('id', $metaId)->lockForUpdate()->first();
        abort_if($meta === null, 404);
        abort_unless($meta->situacao !== 'pausada', 409);

        return $meta;
    }

    private function contaValida(int $usuario, int $contaId): object
    {
        $conta = DB::table('contas')->where('usuario_id', $usuario)
            ->where('id', $contaId)->lockForUpdate()->first();
        abort_if($conta === null, 404);
        if ($conta->situacao !== 'ativa' || $conta->moeda !== 'BRL' || $conta->tipo === 'cartao_credito') {
            throw ValidationException::withMessages([
                'conta_id' => 'Selecione uma conta ativa em reais que não seja cartão de crédito.',
            ]);
        }

        return $conta;
    }

    private function criarTransacao(int $usuario, object $meta, object $conta, string $valor, string $data): int
    {
        $descricao = "Aporte para meta: {$meta->nome}";
        $saida = bcsub('0', $valor, 2);

        return DB::table('transacoes')->insertGetId([
            'uuid' => IdentificadorPublico::novo(),
            'usuario_id' => $usuario, 'conta_id' => $conta->id,
            'data_efetiva' => $data, 'valor' => $saida, 'moeda' => 'BRL',
            'descricao_original' => $descricao, 'descricao_normalizada' => Identidade::descricao($descricao),
            'fingerprint' => Identidade::fingerprint($usuario, $conta->id, $data, $saida, 'BRL', null, $descricao),
            'natureza' => 'ajuste', 'situacao' => 'confirmada', 'origem' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array{data: string, observacao?: string|null} $dados */
    private function criarAporte(
        int $usuario,
        int $metaId,
        int $contaId,
        int $transacaoId,
        string $valor,
        array $dados,
    ): int {
        return DB::table('aportes_metas')->insertGetId([
            'uuid' => IdentificadorPublico::novo(),
            'usuario_id' => $usuario, 'meta_id' => $metaId, 'conta_id' => $contaId,
            'transacao_id' => $transacaoId, 'valor' => $valor, 'data' => $dados['data'],
            'observacao' => isset($dados['observacao']) ? trim($dados['observacao']) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
