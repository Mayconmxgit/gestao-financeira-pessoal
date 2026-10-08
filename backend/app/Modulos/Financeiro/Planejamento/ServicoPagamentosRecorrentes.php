<?php

namespace App\Modulos\Financeiro\Planejamento;

use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use App\Modulos\Financeiro\Suporte\IdentidadeTransacao as Identidade;
use App\Modulos\Financeiro\Suporte\IdentificadorPublico;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ServicoPagamentosRecorrentes
{
    public function __construct(private readonly CalculadoraAgendaRecorrente $agenda) {}

    public function pagar(int $usuario, int $id, string $data): array
    {
        return DB::transaction(function () use ($usuario, $id, $data): array {
            $pagamento = DB::table('pagamentos_recorrentes')->where('usuario_id', $usuario)
                ->where('id', $id)->lockForUpdate()->first();
            abort_if($pagamento === null, 404);
            abort_unless($pagamento->situacao === 'ativo' && $pagamento->proximo_vencimento !== null, 409);
            $conta = Entrada::conta($usuario, (int) $pagamento->conta_id);
            abort_unless($conta->situacao === 'ativa' && $conta->moeda === 'BRL', 409);
            $this->validarCategoria($usuario, $pagamento->categoria_id);

            $transacao = $this->criarTransacao($usuario, $pagamento, $conta, $data);
            DB::table('execucoes_pagamentos_recorrentes')->insert([
                'usuario_id' => $usuario, 'pagamento_recorrente_id' => $id, 'transacao_id' => $transacao,
                'vencimento' => $pagamento->proximo_vencimento, 'pago_em' => $data, 'valor' => $pagamento->valor,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $proximo = $this->agenda->proximo($pagamento);
            DB::table('pagamentos_recorrentes')->where('id', $id)->update([
                'proximo_vencimento' => $proximo, 'situacao' => $proximo === null ? 'encerrado' : 'ativo', 'updated_at' => now(),
            ]);
            Entrada::auditar($usuario, 'pagamento_recorrente_confirmado', 'pagamentos_recorrentes', $id, [
                'vencimento' => $pagamento->proximo_vencimento, 'transacao_id' => $transacao,
            ]);

            return ['pagamento' => DB::table('pagamentos_recorrentes')->find($id), 'transacao' => DB::table('transacoes')->find($transacao)];
        });
    }

    private function criarTransacao(int $usuario, object $pagamento, object $conta, string $data): int
    {
        $valor = bcsub('0', (string) $pagamento->valor, 2);
        $natureza = $conta->tipo === 'cartao_credito' ? 'compra_cartao' : 'saida';

        return DB::table('transacoes')->insertGetId([
            'uuid' => IdentificadorPublico::novo(),
            'usuario_id' => $usuario, 'conta_id' => $conta->id, 'categoria_id' => $pagamento->categoria_id,
            'data_efetiva' => $data, 'valor' => $valor, 'moeda' => 'BRL',
            'descricao_original' => $pagamento->descricao, 'descricao_normalizada' => Identidade::descricao($pagamento->descricao),
            'fingerprint' => Identidade::fingerprint($usuario, $conta->id, $data, $valor, 'BRL', null, $pagamento->descricao),
            'natureza' => $natureza, 'situacao' => 'confirmada', 'origem' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function validarCategoria(int $usuario, ?int $categoria): void
    {
        Entrada::categoria($usuario, $categoria);
        if ($categoria !== null && DB::table('categorias')->where('id', $categoria)->value('tipo') !== 'despesa') {
            throw ValidationException::withMessages(['categoria_id' => 'Use uma categoria de despesa.']);
        }
    }
}
