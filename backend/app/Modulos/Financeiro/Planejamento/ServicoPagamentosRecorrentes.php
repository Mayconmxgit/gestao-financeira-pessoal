<?php

namespace App\Modulos\Financeiro\Planejamento;

use App\Modulos\Financeiro\Cartoes\ServicoFaturasCartao;
use App\Modulos\Financeiro\Suporte\CodigoMovimentacao as Codigo;
use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use App\Modulos\Financeiro\Suporte\IdentidadeTransacao as Identidade;
use App\Modulos\Financeiro\Suporte\IdentificadorPublico;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

final class ServicoPagamentosRecorrentes
{
    public function __construct(
        private readonly CalculadoraAgendaRecorrente $agenda,
        private readonly ServicoFaturasCartao $faturas,
    ) {}

    public function pagar(int $usuario, int $id, string $data): array
    {
        return DB::transaction(function () use ($usuario, $id, $data): array {
            $pagamento = DB::table('pagamentos_recorrentes')->where('usuario_id', $usuario)
                ->where('id', $id)->lockForUpdate()->first();
            abort_if($pagamento === null, 404);
            abort_unless($pagamento->situacao === 'ativo' && $pagamento->proximo_vencimento !== null, 409);

            return $this->registrarPagamento($usuario, $pagamento, $data);
        });
    }

    public function processarAutomaticas(string $dataLimite): int
    {
        $this->validarData($dataLimite);
        $processadas = 0;
        DB::table('pagamentos_recorrentes')
            ->where('cobranca_automatica', true)->where('situacao', 'ativo')
            ->whereNotNull('proximo_vencimento')->where('proximo_vencimento', '<=', $dataLimite)
            ->orderBy('id')->chunkById(100, function ($pagamentos) use ($dataLimite, &$processadas): void {
                foreach ($pagamentos as $pagamento) {
                    try {
                        $processadas += $this->processarRecorrenciaAte(
                            (int) $pagamento->usuario_id,
                            (int) $pagamento->id,
                            $dataLimite
                        );
                    } catch (Throwable $falha) {
                        DB::table('pagamentos_recorrentes')->where('id', $pagamento->id)->update([
                            'falha_automatica' => $falha instanceof ValidationException ? 'limite_insuficiente' : 'erro_processamento',
                            'falha_automatica_em' => now(), 'updated_at' => now(),
                        ]);
                        Log::error('Falha ao processar cobrança recorrente automática', [
                            'pagamento_recorrente_id' => $pagamento->id,
                            'tipo' => get_class($falha),
                        ]);
                    }
                }
            });

        return $processadas;
    }

    private function processarRecorrenciaAte(int $usuario, int $id, string $dataLimite): int
    {
        $processadas = 0;
        while ($processadas < 400) {
            $vencimento = DB::table('pagamentos_recorrentes')->where('usuario_id', $usuario)
                ->where('id', $id)->value('proximo_vencimento');
            if (! is_string($vencimento) || $vencimento > $dataLimite) {
                return $processadas;
            }
            if (! $this->pagarAutomaticamente($usuario, $id, $vencimento, $dataLimite)) {
                return $processadas;
            }
            $processadas++;
        }

        Log::warning('Limite de cobranças automáticas atingido', ['pagamento_recorrente_id' => $id]);

        return $processadas;
    }

    private function pagarAutomaticamente(int $usuario, int $id, string $vencimento, string $dataLimite): bool
    {
        return DB::transaction(function () use ($usuario, $id, $vencimento, $dataLimite): bool {
            $pagamento = DB::table('pagamentos_recorrentes')->where('usuario_id', $usuario)
                ->where('id', $id)->lockForUpdate()->first();
            $elegivel = $pagamento !== null && (bool) $pagamento->cobranca_automatica
                && $pagamento->situacao === 'ativo' && $pagamento->proximo_vencimento === $vencimento
                && $vencimento <= $dataLimite;
            if (! $elegivel) {
                return false;
            }
            $conta = Entrada::conta($usuario, (int) $pagamento->conta_id);
            if ($conta->tipo !== 'cartao_credito') {
                throw new InvalidArgumentException('Conta incompatível com cobrança automática.');
            }
            $this->registrarPagamento($usuario, $pagamento, $vencimento, true);

            return true;
        });
    }

    private function registrarPagamento(int $usuario, object $pagamento, string $data, bool $automatico = false): array
    {
        $conta = Entrada::conta($usuario, (int) $pagamento->conta_id);
        abort_unless($conta->situacao === 'ativa' && $conta->moeda === 'BRL', 409);
        $this->validarCategoria($usuario, $pagamento->categoria_id);
        $transacao = $this->criarTransacao($usuario, $pagamento, $conta, $data);
        DB::table('execucoes_pagamentos_recorrentes')->insert([
            'usuario_id' => $usuario, 'pagamento_recorrente_id' => $pagamento->id, 'transacao_id' => $transacao,
            'vencimento' => $pagamento->proximo_vencimento, 'pago_em' => $data, 'valor' => $pagamento->valor,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proximo = $this->agenda->proximo($pagamento);
        DB::table('pagamentos_recorrentes')->where('id', $pagamento->id)->update([
            'proximo_vencimento' => $proximo, 'situacao' => $proximo === null ? 'encerrado' : 'ativo',
            'falha_automatica' => null, 'falha_automatica_em' => null, 'updated_at' => now(),
        ]);
        Entrada::auditar($usuario, 'pagamento_recorrente_confirmado', 'pagamentos_recorrentes', $pagamento->id, [
            'vencimento' => $pagamento->proximo_vencimento, 'transacao_id' => $transacao,
            'automatico' => $automatico,
        ]);

        return ['pagamento' => DB::table('pagamentos_recorrentes')->find($pagamento->id), 'transacao' => DB::table('transacoes')->find($transacao)];
    }

    private function validarData(string $data): void
    {
        $valida = \DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        if ($valida === false || $valida->format('Y-m-d') !== $data) {
            throw new InvalidArgumentException('Data limite inválida.');
        }
    }

    private function criarTransacao(int $usuario, object $pagamento, object $conta, string $data): int
    {
        $valor = bcsub('0', (string) $pagamento->valor, 2);
        $natureza = $conta->tipo === 'cartao_credito' ? 'compra_cartao' : 'saida';
        $fatura = $conta->tipo === 'cartao_credito'
            ? $this->faturas->prepararCompra($usuario, (int) $conta->id, $valor, $data) : null;

        $id = DB::table('transacoes')->insertGetId([
            'uuid' => IdentificadorPublico::novo(), 'codigo' => Codigo::novo($usuario),
            'usuario_id' => $usuario, 'conta_id' => $conta->id, 'fatura_cartao_id' => $fatura,
            'categoria_id' => $pagamento->categoria_id,
            'data_efetiva' => $data, 'valor' => $valor, 'moeda' => 'BRL',
            'descricao_original' => $pagamento->descricao, 'descricao_normalizada' => Identidade::descricao($pagamento->descricao),
            'fingerprint' => Identidade::fingerprint($usuario, $conta->id, $data, $valor, 'BRL', null, $pagamento->descricao),
            'natureza' => $natureza, 'situacao' => 'confirmada', 'origem' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($fatura !== null) {
            $this->faturas->sincronizar($fatura);
        }

        return $id;
    }

    private function validarCategoria(int $usuario, ?int $categoria): void
    {
        Entrada::categoria($usuario, $categoria);
        if ($categoria !== null && DB::table('categorias')->where('id', $categoria)->value('tipo') !== 'despesa') {
            throw ValidationException::withMessages(['categoria_id' => 'Use uma categoria de despesa.']);
        }
    }
}
