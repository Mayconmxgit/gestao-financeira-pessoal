<?php

namespace App\Modulos\Financeiro\Cartoes;

use App\Modulos\Financeiro\Suporte\CodigoMovimentacao as Codigo;
use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use App\Modulos\Financeiro\Suporte\IdentidadeTransacao as Identidade;
use App\Modulos\Financeiro\Suporte\IdentificadorPublico;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ServicoFaturasCartao
{
    public function iniciar(int $usuario, int $cartaoId): void
    {
        $cartao = $this->cartao($usuario, $cartaoId);
        $this->garantirAberta($usuario, $cartao, CarbonImmutable::today(config('app.timezone')));
    }

    public function indicadores(int $usuario, int $cartaoId): array
    {
        $cartao = $this->cartao($usuario, $cartaoId);
        $utilizado = $this->limiteUtilizado($usuario, $cartaoId);
        $limite = Entrada::moeda((string) $cartao->limite_credito);
        $aberta = DB::table('faturas_cartao')->where('cartao_id', $cartaoId)->where('situacao', 'aberta')->first();

        return [
            'limite_utilizado' => $utilizado,
            'limite_disponivel' => bcsub($limite, $utilizado, 2),
            'fatura_atual' => $aberta === null ? null : $this->apresentar($aberta),
        ];
    }

    public function validarNovoLimite(int $usuario, int $cartaoId, string $limite): void
    {
        if (bccomp($limite, $this->limiteUtilizado($usuario, $cartaoId), 2) < 0) {
            throw ValidationException::withMessages(['limite_credito' => 'O limite não pode ser menor que o valor utilizado.']);
        }
    }

    public function prepararCompra(int $usuario, int $cartaoId, string $valor, string $data): int
    {
        $cartao = DB::table('contas')->where('usuario_id', $usuario)->where('id', $cartaoId)
            ->lockForUpdate()->first();
        abort_if($cartao === null, 404);
        abort_unless($cartao->tipo === 'cartao_credito' && $cartao->situacao === 'ativa', 409);
        $this->fecharDoCartao($usuario, $cartao, CarbonImmutable::today(config('app.timezone')));
        $fatura = $this->garantirAberta($usuario, $cartao, CarbonImmutable::parse($data));
        if ($fatura->situacao === 'paga') {
            throw ValidationException::withMessages(['data' => 'A data da compra pertence a uma fatura já paga. Informe uma data de uma fatura pendente.']);
        }
        $utilizado = $this->limiteUtilizado($usuario, $cartaoId);
        $compra = bcsub('0', Entrada::moeda($valor), 2);
        if (bccomp(bcadd($utilizado, $compra, 2), (string) $cartao->limite_credito, 2) > 0) {
            throw ValidationException::withMessages(['valor' => 'Limite insuficiente no cartão.']);
        }

        return (int) $fatura->id;
    }

    public function prepararEdicaoCompra(int $usuario, object $transacao, int $cartaoId, string $valor, string $data): int
    {
        $faturaAtual = $transacao->fatura_cartao_id === null ? null : DB::table('faturas_cartao')->where('id', $transacao->fatura_cartao_id)->first();
        abort_if($faturaAtual !== null && $faturaAtual->situacao !== 'aberta', 409, 'Uma compra de fatura fechada não pode ser alterada.');
        $cartao = DB::table('contas')->where('usuario_id', $usuario)->where('id', $cartaoId)->lockForUpdate()->first();
        abort_unless($cartao !== null && $cartao->tipo === 'cartao_credito', 422);
        $fatura = $this->garantirAberta($usuario, $cartao, CarbonImmutable::parse($data));
        if ($fatura->situacao === 'paga') {
            throw ValidationException::withMessages(['data' => 'A data da compra pertence a uma fatura já paga. Informe uma data de uma fatura pendente.']);
        }
        $utilizadoSemAtual = bcsub($this->limiteUtilizado($usuario, $cartaoId), bcsub('0', (string) $transacao->valor, 2), 2);
        if (bccomp(bcadd($utilizadoSemAtual, bcsub('0', $valor, 2), 2), (string) $cartao->limite_credito, 2) > 0) {
            throw ValidationException::withMessages(['valor' => 'Limite insuficiente no cartão.']);
        }

        return (int) $fatura->id;
    }

    public function faturaParaCredito(int $usuario, int $cartaoId, ?int $faturaOriginal): int
    {
        if ($faturaOriginal !== null) {
            $fatura = DB::table('faturas_cartao')->where('id', $faturaOriginal)->first();
            if ($fatura?->situacao === 'aberta') {
                return (int) $fatura->id;
            }
        }
        $cartao = $this->cartao($usuario, $cartaoId);

        return (int) $this->garantirAberta($usuario, $cartao, CarbonImmutable::today(config('app.timezone')))->id;
    }

    public function sincronizar(int $faturaId): void
    {
        $fatura = DB::table('faturas_cartao')->where('id', $faturaId)->first();
        if ($fatura === null || $fatura->situacao === 'paga') {
            return;
        }
        if ($fatura->situacao === 'aberta') {
            $this->recalcular($fatura);
        } else {
            $this->recalcularCorrecao($fatura);
        }
    }

    public function fecharTodas(string $data): int
    {
        $hoje = CarbonImmutable::parse($data, config('app.timezone'))->startOfDay();
        $quantidade = 0;
        DB::table('contas')->where('tipo', 'cartao_credito')->where('situacao', 'ativa')
            ->orderBy('id')->chunkById(100, function ($cartoes) use ($hoje, &$quantidade): void {
                foreach ($cartoes as $cartao) {
                    $quantidade += DB::transaction(fn (): int => $this->fecharDoCartao((int) $cartao->usuario_id, $cartao, $hoje));
                }
            });
        DB::table('faturas_cartao')->where('situacao', 'fechada')->where('vencimento', '<', $hoje->toDateString())
            ->update(['situacao' => 'vencida', 'updated_at' => now()]);

        return $quantidade;
    }

    public function listar(int $usuario, int $cartaoId): array
    {
        $cartao = $this->cartao($usuario, $cartaoId);
        DB::transaction(fn (): int => $this->fecharDoCartao($usuario, $cartao, CarbonImmutable::today(config('app.timezone'))));

        return DB::table('faturas_cartao')->where('usuario_id', $usuario)->where('cartao_id', $cartaoId)
            ->orderByDesc('fechamento')->get()->map(fn (object $fatura): array => $this->apresentar($fatura))->all();
    }

    public function mostrar(int $usuario, int $cartaoId, int $faturaId): array
    {
        $this->cartao($usuario, $cartaoId);
        $fatura = DB::table('faturas_cartao')->where('usuario_id', $usuario)->where('cartao_id', $cartaoId)
            ->where('id', $faturaId)->first();
        abort_if($fatura === null, 404);

        $transacoes = DB::table('transacoes')
            ->where('usuario_id', $usuario)->where('fatura_cartao_id', $faturaId)
            ->where('situacao', '<>', 'cancelada')
            ->orderBy('data_efetiva')->orderBy('id')->get();
        $ids = $transacoes->pluck('id');
        $referenciadas = DB::table('transacoes')->whereIn('transacao_relacionada_id', $ids)
            ->pluck('transacao_relacionada_id')->flip();
        $vinculadas = $this->idsVinculadosOperacionalmente($ids);
        $permitida = $this->correcaoPermitida($fatura);
        $transacoes->transform(function (object $transacao) use ($permitida, $referenciadas, $vinculadas): object {
            $simples = $permitida && $transacao->situacao === 'confirmada' && $transacao->natureza === 'compra_cartao'
                && $transacao->transacao_relacionada_id === null && ! $referenciadas->has($transacao->id);
            $transacao->corrigivel = $simples;
            $transacao->excluivel = $simples && ! $vinculadas->has($transacao->id);

            return $transacao;
        });

        return $this->apresentar($fatura) + ['transacoes' => $transacoes->all()];
    }

    public function pagar(int $usuario, int $cartaoId, int $faturaId, ?int $contaPagamentoId, string $data, ?int $transacaoId, ?string $codigoMovimentacao = null, bool $pagamentoExterno = false): array
    {
        abort_unless($pagamentoExterno
            ? $contaPagamentoId === null && $transacaoId === null && $codigoMovimentacao === null
            : $contaPagamentoId !== null, 422);

        return DB::transaction(function () use ($usuario, $cartaoId, $faturaId, $contaPagamentoId, $data, $transacaoId, $codigoMovimentacao, $pagamentoExterno): array {
            $fatura = DB::table('faturas_cartao')->where('usuario_id', $usuario)->where('cartao_id', $cartaoId)
                ->where('id', $faturaId)->lockForUpdate()->first();
            abort_if($fatura === null, 404);
            abort_unless(in_array($fatura->situacao, ['fechada', 'vencida'], true) && bccomp((string) $fatura->valor_total, '0', 2) > 0, 409);
            $cartao = $this->cartao($usuario, $cartaoId);
            $debito = null;
            if (! $pagamentoExterno) {
                $conta = $this->vincularContaPagamento($usuario, $cartao, $fatura, $contaPagamentoId);
                if ($codigoMovimentacao !== null) {
                    $transacaoId = Codigo::resolver($usuario, $codigoMovimentacao);
                    if ($transacaoId === null) {
                        throw ValidationException::withMessages(['codigo_movimentacao' => 'Código de movimentação inválido.']);
                    }
                }
                $debito = $transacaoId === null
                    ? $this->criarDebito($usuario, $conta, $fatura, $data)
                    : $this->associarDebito($usuario, $conta, $fatura, $transacaoId);
            }
            $credito = $this->criarCreditoCartao($usuario, $cartao, $fatura, $data, $debito);
            if ($debito !== null) {
                DB::table('transacoes')->where('id', $debito)->update(['transacao_relacionada_id' => $credito, 'updated_at' => now()]);
            }
            DB::table('faturas_cartao')->where('id', $faturaId)->update([
                'situacao' => 'paga', 'transacao_pagamento_id' => $debito, 'transacao_cartao_id' => $credito,
                'paga_em' => now(), 'updated_at' => now(),
            ]);
            Entrada::auditar($usuario, $pagamentoExterno ? 'fatura_cartao_paga_externamente' : 'fatura_cartao_paga', 'faturas_cartao', $faturaId, [
                'transacao_pagamento_id' => $debito, 'transacao_cartao_id' => $credito,
                'pagamento_externo' => $pagamentoExterno, 'data' => $data, 'valor' => (string) $fatura->valor_total,
            ]);

            return $this->apresentar(DB::table('faturas_cartao')->find($faturaId));
        });
    }

    public function adiantar(int $usuario, int $cartaoId, int $faturaId, int $contaPagamentoId, string $data, string $valor): array
    {
        return DB::transaction(function () use ($usuario, $cartaoId, $faturaId, $contaPagamentoId, $data, $valor): array {
            $cartao = DB::table('contas')->where('usuario_id', $usuario)->where('id', $cartaoId)
                ->where('tipo', 'cartao_credito')->where('situacao', 'ativa')->lockForUpdate()->first();
            abort_if($cartao === null, 404);
            $fatura = DB::table('faturas_cartao')->where('usuario_id', $usuario)->where('cartao_id', $cartaoId)
                ->where('id', $faturaId)->lockForUpdate()->first();
            abort_if($fatura === null, 404);
            abort_unless($fatura->situacao === 'aberta' && bccomp((string) $fatura->valor_total, '0', 2) > 0, 409);

            $valor = Entrada::moeda($valor);
            if (bccomp($valor, '0', 2) <= 0) {
                throw ValidationException::withMessages(['valor' => 'O valor deve ser maior que zero.']);
            }
            if (bccomp($valor, (string) $fatura->valor_total, 2) >= 0) {
                throw ValidationException::withMessages(['valor' => 'O adiantamento deve ser menor que o valor atual da fatura.']);
            }

            $conta = $this->vincularContaPagamento($usuario, $cartao, $fatura, $contaPagamentoId);

            $saldo = $this->saldoDisponivel($usuario, $conta, $data);
            if (bccomp($saldo, $valor, 2) < 0) {
                throw new SaldoInsuficiente($conta->nome, Entrada::moeda($saldo), $valor, bcsub($valor, $saldo, 2));
            }

            $debito = DB::table('transacoes')->insertGetId($this->dadosPagamento(
                $usuario, $conta, $data, bcsub('0', $valor, 2), 'manual', 'Adiantamento de fatura',
            ));
            $credito = DB::table('transacoes')->insertGetId($this->dadosPagamento(
                $usuario, $cartao, $data, $valor, 'conciliacao', 'Adiantamento de fatura',
            ) + ['transacao_relacionada_id' => $debito, 'fatura_cartao_id' => $faturaId]);
            DB::table('transacoes')->where('id', $debito)->update([
                'transacao_relacionada_id' => $credito, 'updated_at' => now(),
            ]);

            $this->sincronizar($faturaId);
            $atualizada = DB::table('faturas_cartao')->find($faturaId);
            Entrada::auditar($usuario, 'fatura_cartao_adiantada', 'faturas_cartao', $faturaId, [
                'valor' => $valor, 'valor_restante' => $atualizada->valor_total,
                'transacao_pagamento_id' => $debito, 'transacao_cartao_id' => $credito,
            ]);

            return $this->apresentar($atualizada);
        });
    }

    public function fecharAgora(int $usuario, int $cartaoId, int $faturaId): array
    {
        return DB::transaction(function () use ($usuario, $cartaoId, $faturaId): array {
            $hoje = CarbonImmutable::today(config('app.timezone'));
            $cartao = DB::table('contas')->where('usuario_id', $usuario)->where('id', $cartaoId)
                ->where('tipo', 'cartao_credito')->where('situacao', 'ativa')->lockForUpdate()->first();
            abort_if($cartao === null, 404);
            $fatura = DB::table('faturas_cartao')->where('usuario_id', $usuario)->where('cartao_id', $cartaoId)
                ->where('id', $faturaId)->lockForUpdate()->first();
            abort_if($fatura === null, 404);
            abort_unless($this->fechamentoAntecipadoPermitido($fatura, $hoje), 409);

            $atualizada = $this->recalcular($fatura);
            $fechamentoOriginal = CarbonImmutable::parse($fatura->fechamento, config('app.timezone'));
            $situacao = bccomp((string) $atualizada->valor_total, '0', 2) === 0 ? 'paga' : 'fechada';
            DB::table('faturas_cartao')->where('id', $faturaId)->update([
                'fim_periodo' => $hoje->toDateString(), 'fechamento' => $hoje->toDateString(),
                'situacao' => $situacao, 'paga_em' => $situacao === 'paga' ? now() : null, 'updated_at' => now(),
            ]);

            $proximoFechamento = $this->dataNoMes($fechamentoOriginal->addMonth(), (int) $cartao->dia_fechamento);
            $criada = DB::table('faturas_cartao')->insertOrIgnore([
                'uuid' => IdentificadorPublico::novo(), 'usuario_id' => $usuario, 'cartao_id' => $cartaoId,
                'conta_pagamento_id' => $cartao->conta_pagamento_id, 'inicio_periodo' => $hoje->toDateString(),
                'fim_periodo' => $proximoFechamento->subDay()->toDateString(),
                'fechamento' => $proximoFechamento->toDateString(),
                'vencimento' => $this->vencimento($proximoFechamento, (int) $cartao->dia_vencimento)->toDateString(),
                'valor_total' => '0.00', 'credito_anterior' => $atualizada->credito_proximo,
                'credito_proximo' => '0.00', 'situacao' => 'aberta', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $proxima = DB::table('faturas_cartao')->where('cartao_id', $cartaoId)
                ->where('fechamento', $proximoFechamento->toDateString())->lockForUpdate()->firstOrFail();
            if ($criada === 1) {
                Entrada::auditar($usuario, 'fatura_cartao_criada', 'faturas_cartao', (int) $proxima->id, [
                    'fechamento' => $proxima->fechamento, 'vencimento' => $proxima->vencimento,
                    'origem' => 'fechamento_antecipado',
                ]);
            }
            DB::table('faturas_cartao')->where('id', $proxima->id)->update([
                'inicio_periodo' => $hoje->toDateString(), 'credito_anterior' => $atualizada->credito_proximo,
                'updated_at' => now(),
            ]);
            $this->sincronizar((int) $proxima->id);
            Entrada::auditar($usuario, 'fatura_cartao_fechada_antecipadamente', 'faturas_cartao', $faturaId, [
                'fechamento_original' => $fatura->fechamento, 'fechamento_antecipado' => $hoje->toDateString(),
                'proxima_fatura_id' => $proxima->id,
            ]);

            return $this->apresentar(DB::table('faturas_cartao')->find($faturaId));
        });
    }

    public function corrigirLancamento(
        int $usuario,
        int $cartaoId,
        int $faturaId,
        int $lancamentoId,
        string $valor,
    ): array {
        return DB::transaction(function () use ($usuario, $cartaoId, $faturaId, $lancamentoId, $valor): array {
            $cartao = DB::table('contas')->where('usuario_id', $usuario)->where('id', $cartaoId)
                ->where('tipo', 'cartao_credito')->lockForUpdate()->first();
            abort_if($cartao === null, 404);
            $fatura = $this->faturaParaCorrecao($usuario, $cartaoId, $faturaId);
            $lancamento = $this->lancamentoParaCorrecao($usuario, $cartaoId, $faturaId, $lancamentoId);
            $valor = Entrada::moeda($valor);
            if (bccomp($valor, '0', 2) <= 0) {
                throw ValidationException::withMessages(['valor' => 'O valor deve ser maior que zero.']);
            }
            $utilizadoSemAtual = bcsub($this->limiteUtilizado($usuario, $cartaoId), $this->valorAbsoluto((string) $lancamento->valor), 2);
            if (bccomp(bcadd($utilizadoSemAtual, $valor, 2), (string) $cartao->limite_credito, 2) > 0) {
                throw ValidationException::withMessages(['valor' => 'Limite insuficiente no cartão.']);
            }
            $novoValor = bcsub('0', $valor, 2);
            DB::table('transacoes')->where('id', $lancamentoId)->update([
                'valor' => $novoValor,
                'fingerprint' => Identidade::fingerprint(
                    $usuario, $cartaoId, $lancamento->data_efetiva, $novoValor, $lancamento->moeda,
                    $lancamento->documento, $lancamento->descricao_original,
                ),
                'updated_at' => now(),
            ]);
            $this->recalcularCorrecao($fatura);
            Entrada::auditar($usuario, 'fatura_cartao_lancamento_corrigido', 'transacoes', $lancamentoId, [
                'fatura_id' => $faturaId, 'valor_anterior' => Entrada::moeda((string) $lancamento->valor),
                'valor_atual' => $novoValor,
            ]);

            return $this->mostrar($usuario, $cartaoId, $faturaId);
        });
    }

    public function cancelarLancamento(
        int $usuario,
        int $cartaoId,
        int $faturaId,
        int $lancamentoId,
        string $motivo,
    ): array {
        return DB::transaction(function () use ($usuario, $cartaoId, $faturaId, $lancamentoId, $motivo): array {
            $cartao = DB::table('contas')->where('usuario_id', $usuario)->where('id', $cartaoId)
                ->where('tipo', 'cartao_credito')->lockForUpdate()->first();
            abort_if($cartao === null, 404);
            $fatura = $this->faturaParaCorrecao($usuario, $cartaoId, $faturaId);
            $lancamento = $this->lancamentoParaCorrecao($usuario, $cartaoId, $faturaId, $lancamentoId);
            abort_if($this->idsVinculadosOperacionalmente(collect([$lancamentoId]))->isNotEmpty(), 409);
            DB::table('transacoes')->where('id', $lancamentoId)->update([
                'situacao' => 'cancelada', 'updated_at' => now(),
            ]);
            $this->recalcularCorrecao($fatura);
            Entrada::auditar($usuario, 'fatura_cartao_lancamento_cancelado', 'transacoes', $lancamentoId, [
                'fatura_id' => $faturaId, 'valor' => $lancamento->valor, 'motivo' => $motivo,
            ]);

            return $this->mostrar($usuario, $cartaoId, $faturaId);
        });
    }

    public function resumo(int $usuario, string $moeda): array
    {
        $hoje = CarbonImmutable::today(config('app.timezone'));
        $cartoes = DB::table('contas as cartao')->leftJoin('contas as banco', 'banco.id', '=', 'cartao.conta_pagamento_id')
            ->where('cartao.usuario_id', $usuario)->where('cartao.tipo', 'cartao_credito')->where('cartao.moeda', $moeda)
            ->select('cartao.*', 'banco.nome as conta_pagamento_nome',
                DB::raw('COALESCE(cartao.instituicao, banco.instituicao) as banco_instituicao'))->orderBy('cartao.id')->get();
        $itens = [];
        foreach ($cartoes as $cartao) {
            $aberta = DB::table('faturas_cartao')->where('cartao_id', $cartao->id)->where('situacao', 'aberta')->first();
            $pendente = DB::table('faturas_cartao')->where('cartao_id', $cartao->id)
                ->whereIn('situacao', ['fechada', 'vencida'])->orderBy('vencimento')->first();
            $fatura = $pendente ?? $aberta;
            if ($fatura === null) {
                continue;
            }
            $dias = $hoje->diffInDays(CarbonImmutable::parse($fatura->fechamento), false);
            $alerta = $fatura->situacao === 'vencida' ? 'vencida'
                : ($fatura->situacao === 'fechada' ? 'fechada' : (($dias >= 0 && $dias <= 3) ? 'fechamento_proximo' : 'normal'));
            $itens[] = [
                'cartao_id' => $cartao->id, 'cartao_nome' => $cartao->nome,
                'conta_pagamento_id' => $cartao->conta_pagamento_id, 'conta_pagamento_nome' => $cartao->conta_pagamento_nome,
                'instituicao' => $cartao->banco_instituicao, 'valor' => Entrada::moeda((string) $fatura->valor_total),
                'fechamento' => $fatura->fechamento, 'vencimento' => $fatura->vencimento,
                'situacao' => $fatura->situacao, 'alerta' => $alerta,
            ];
        }

        return ['total' => array_reduce($itens, fn (string $total, array $item): string => bcadd($total, $item['valor'], 2), '0.00'), 'itens' => $itens];
    }

    private function fecharDoCartao(int $usuario, object $cartao, CarbonImmutable $hoje): int
    {
        $fechadas = 0;
        $abertas = DB::table('faturas_cartao')->where('usuario_id', $usuario)->where('cartao_id', $cartao->id)
            ->where('situacao', 'aberta')->where('fechamento', '<=', $hoje->toDateString())->lockForUpdate()->get();
        foreach ($abertas as $fatura) {
            $this->sincronizar((int) $fatura->id);
            $atualizada = DB::table('faturas_cartao')->where('id', $fatura->id)->first();
            DB::table('faturas_cartao')->where('id', $fatura->id)->update([
                'situacao' => bccomp((string) $atualizada->valor_total, '0', 2) === 0 ? 'paga' : 'fechada',
                'paga_em' => bccomp((string) $atualizada->valor_total, '0', 2) === 0 ? now() : null,
                'updated_at' => now(),
            ]);
            Entrada::auditar($usuario, 'fatura_cartao_fechada', 'faturas_cartao', (int) $fatura->id, [
                'fechamento' => $fatura->fechamento, 'valor' => $atualizada->valor_total,
            ]);
            $fechadas++;
        }
        $this->garantirAberta($usuario, $cartao, $hoje);

        return $fechadas;
    }

    private function recalcular(object $fatura): object
    {
        $soma = Entrada::moeda((string) DB::table('transacoes')->where('fatura_cartao_id', $fatura->id)
            ->where('situacao', 'confirmada')->sum('valor'));
        $valor = bcsub(bcsub('0', $soma, 2), (string) $fatura->credito_anterior, 2);
        DB::table('faturas_cartao')->where('id', $fatura->id)->update([
            'valor_total' => bccomp($valor, '0', 2) > 0 ? $valor : '0.00',
            'credito_proximo' => bccomp($valor, '0', 2) < 0 ? bcsub('0', $valor, 2) : '0.00',
            'updated_at' => now(),
        ]);

        return DB::table('faturas_cartao')->find($fatura->id);
    }

    private function recalcularCorrecao(object $fatura): void
    {
        $atualizada = $this->recalcular($fatura);
        $proxima = DB::table('faturas_cartao')->where('cartao_id', $fatura->cartao_id)
            ->where('fechamento', '>', $fatura->fechamento)->orderBy('fechamento')->lockForUpdate()->first();
        if ($proxima === null || $proxima->situacao !== 'aberta') {
            return;
        }
        DB::table('faturas_cartao')->where('id', $proxima->id)->update([
            'credito_anterior' => $atualizada->credito_proximo, 'updated_at' => now(),
        ]);
        $this->sincronizar((int) $proxima->id);
    }

    private function faturaParaCorrecao(int $usuario, int $cartaoId, int $faturaId): object
    {
        $fatura = DB::table('faturas_cartao')->where('usuario_id', $usuario)->where('cartao_id', $cartaoId)
            ->where('id', $faturaId)->lockForUpdate()->first();
        abort_if($fatura === null, 404);
        abort_unless($this->correcaoPermitida($fatura), 409);

        return $fatura;
    }

    private function lancamentoParaCorrecao(int $usuario, int $cartaoId, int $faturaId, int $lancamentoId): object
    {
        $lancamento = DB::table('transacoes')->where('usuario_id', $usuario)->where('conta_id', $cartaoId)
            ->where('fatura_cartao_id', $faturaId)->where('id', $lancamentoId)->lockForUpdate()->first();
        abort_if($lancamento === null, 404);
        $simples = $lancamento->situacao === 'confirmada' && $lancamento->natureza === 'compra_cartao'
            && $lancamento->transacao_relacionada_id === null;
        $referenciada = DB::table('transacoes')->where('transacao_relacionada_id', $lancamentoId)->exists();
        abort_unless($simples && ! $referenciada, 409);

        return $lancamento;
    }

    private function idsVinculadosOperacionalmente(Collection $ids): Collection
    {
        $vinculadas = collect();
        foreach ([
            ['execucoes_pagamentos_recorrentes', 'transacao_id'],
            ['aportes_metas', 'transacao_id'],
            ['emprestimos', 'transacao_recebimento_id'],
            ['parcelas_emprestimo', 'transacao_principal_id'],
            ['parcelas_emprestimo', 'transacao_encargos_id'],
            ['parcelas_emprestimo', 'transacao_pagamento_id'],
            ['faturas_cartao', 'transacao_pagamento_id'],
            ['faturas_cartao', 'transacao_cartao_id'],
        ] as [$tabela, $campo]) {
            $vinculadas = $vinculadas->merge(DB::table($tabela)->whereIn($campo, $ids)->pluck($campo));
        }

        return $vinculadas->flip();
    }

    private function correcaoPermitida(object $fatura): bool
    {
        return $fatura->situacao === 'fechada'
            && CarbonImmutable::today(config('app.timezone'))->lessThan(CarbonImmutable::parse($fatura->vencimento));
    }

    private function fechamentoAntecipadoPermitido(object $fatura, CarbonImmutable $hoje): bool
    {
        return $fatura->situacao === 'aberta'
            && $hoje->greaterThan(CarbonImmutable::parse($fatura->inicio_periodo))
            && $hoje->lessThan(CarbonImmutable::parse($fatura->fechamento));
    }

    private function valorAbsoluto(string $valor): string
    {
        return bccomp($valor, '0', 2) < 0 ? bcsub('0', $valor, 2) : Entrada::moeda($valor);
    }

    private function garantirAberta(int $usuario, object $cartao, CarbonImmutable $referencia): object
    {
        $porPeriodo = DB::table('faturas_cartao')->where('cartao_id', $cartao->id)->where('situacao', 'aberta')
            ->where('inicio_periodo', '<=', $referencia->toDateString())
            ->where('fim_periodo', '>=', $referencia->toDateString())->orderBy('fechamento')->first();
        if ($porPeriodo !== null) {
            return $porPeriodo;
        }
        $fechamento = $this->proximoFechamento($referencia, (int) $cartao->dia_fechamento);
        $existente = DB::table('faturas_cartao')->where('cartao_id', $cartao->id)
            ->where('fechamento', $fechamento->toDateString())->first();
        if ($existente !== null) {
            return $existente;
        }
        $anterior = DB::table('faturas_cartao')->where('cartao_id', $cartao->id)->orderByDesc('fechamento')->first();
        $inicio = $this->dataNoMes($fechamento->subMonth(), (int) $cartao->dia_fechamento);
        $criada = DB::table('faturas_cartao')->insertOrIgnore([
            'uuid' => IdentificadorPublico::novo(), 'usuario_id' => $usuario, 'cartao_id' => $cartao->id,
            'conta_pagamento_id' => $cartao->conta_pagamento_id, 'inicio_periodo' => $inicio->toDateString(),
            'fim_periodo' => $fechamento->subDay()->toDateString(), 'fechamento' => $fechamento->toDateString(),
            'vencimento' => $this->vencimento($fechamento, (int) $cartao->dia_vencimento)->toDateString(),
            'valor_total' => '0.00', 'credito_anterior' => $anterior?->credito_proximo ?? '0.00',
            'credito_proximo' => '0.00', 'situacao' => 'aberta', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $fatura = DB::table('faturas_cartao')->where('cartao_id', $cartao->id)
            ->where('fechamento', $fechamento->toDateString())->firstOrFail();
        if ($criada === 1) {
            Entrada::auditar($usuario, 'fatura_cartao_criada', 'faturas_cartao', (int) $fatura->id, [
                'fechamento' => $fatura->fechamento, 'vencimento' => $fatura->vencimento,
            ]);
        }

        return $fatura;
    }

    private function proximoFechamento(CarbonImmutable $data, int $dia): CarbonImmutable
    {
        $candidato = $this->dataNoMes($data, $dia);

        return $data->startOfDay()->greaterThanOrEqualTo($candidato) ? $this->dataNoMes($data->addMonth(), $dia) : $candidato;
    }

    private function vencimento(CarbonImmutable $fechamento, int $dia): CarbonImmutable
    {
        $candidato = $this->dataNoMes($fechamento, $dia);

        return $candidato->greaterThan($fechamento) ? $candidato : $this->dataNoMes($fechamento->addMonth(), $dia);
    }

    private function dataNoMes(CarbonImmutable $data, int $dia): CarbonImmutable
    {
        return $data->startOfMonth()->day(min($dia, $data->daysInMonth));
    }

    private function limiteUtilizado(int $usuario, int $cartao): string
    {
        $saldo = Entrada::moeda((string) DB::table('transacoes')->where('usuario_id', $usuario)->where('conta_id', $cartao)
            ->where('situacao', 'confirmada')->sum('valor'));

        return bccomp($saldo, '0', 2) < 0 ? bcsub('0', $saldo, 2) : '0.00';
    }

    private function criarDebito(int $usuario, object $conta, object $fatura, string $data): int
    {
        $saldo = $this->saldoDisponivel($usuario, $conta, $data);
        if (bccomp($saldo, (string) $fatura->valor_total, 2) < 0) {
            throw new SaldoInsuficiente($conta->nome, Entrada::moeda($saldo), Entrada::moeda((string) $fatura->valor_total), bcsub((string) $fatura->valor_total, $saldo, 2));
        }
        $valor = bcsub('0', (string) $fatura->valor_total, 2);

        return DB::table('transacoes')->insertGetId($this->dadosPagamento($usuario, $conta, $data, $valor, 'manual'));
    }

    private function vincularContaPagamento(int $usuario, object $cartao, object $fatura, int $contaId): object
    {
        $conta = DB::table('contas')->where('usuario_id', $usuario)->where('id', $contaId)
            ->where('situacao', 'ativa')->where('tipo', '<>', 'cartao_credito')->lockForUpdate()->first();
        abort_unless($conta !== null && $conta->moeda === $cartao->moeda, 422);

        DB::table('contas')->where('id', $cartao->id)->update([
            'conta_pagamento_id' => $conta->id, 'updated_at' => now(),
        ]);
        DB::table('faturas_cartao')->where('id', $fatura->id)->update([
            'conta_pagamento_id' => $conta->id, 'updated_at' => now(),
        ]);
        DB::table('faturas_cartao')->where('cartao_id', $cartao->id)->where('situacao', 'aberta')->update([
            'conta_pagamento_id' => $conta->id, 'updated_at' => now(),
        ]);
        $fatura->conta_pagamento_id = $conta->id;

        return $conta;
    }

    private function associarDebito(int $usuario, object $conta, object $fatura, int $transacaoId): int
    {
        $transacao = DB::table('transacoes')->where('usuario_id', $usuario)->where('id', $transacaoId)->lockForUpdate()->first();
        $valor = bcsub('0', (string) $fatura->valor_total, 2);
        abort_unless($transacao !== null && (int) $transacao->conta_id === (int) $conta->id && $transacao->situacao === 'confirmada'
            && $transacao->transacao_relacionada_id === null && bccomp((string) $transacao->valor, $valor, 2) === 0
            && in_array($transacao->natureza, ['saida', 'pagamento_fatura'], true), 422);
        DB::table('transacoes')->where('id', $transacaoId)->update(['natureza' => 'pagamento_fatura', 'categoria_id' => null, 'updated_at' => now()]);

        return $transacaoId;
    }

    private function criarCreditoCartao(int $usuario, object $cartao, object $fatura, string $data, ?int $debito): int
    {
        $descricao = $debito === null ? 'Pagamento externo de fatura' : 'Pagamento de fatura';

        return DB::table('transacoes')->insertGetId($this->dadosPagamento($usuario, $cartao, $data, (string) $fatura->valor_total, 'conciliacao', $descricao) + ['transacao_relacionada_id' => $debito]);
    }

    private function dadosPagamento(
        int $usuario,
        object $conta,
        string $data,
        string $valor,
        string $origem,
        string $descricao = 'Pagamento de fatura',
    ): array {
        return [
            'uuid' => IdentificadorPublico::novo(), 'codigo' => Codigo::novo($usuario),
            'usuario_id' => $usuario, 'conta_id' => $conta->id,
            'data_efetiva' => $data, 'valor' => $valor, 'moeda' => $conta->moeda,
            'descricao_original' => $descricao, 'descricao_normalizada' => Identidade::descricao($descricao),
            'fingerprint' => Identidade::fingerprint($usuario, $conta->id, $data, $valor, $conta->moeda, null, $descricao),
            'natureza' => 'pagamento_fatura', 'situacao' => 'confirmada', 'origem' => $origem,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function saldoDisponivel(int $usuario, object $conta, string $data): string
    {
        return bcadd((string) $conta->saldo_inicial, (string) DB::table('transacoes')->where('usuario_id', $usuario)
            ->where('conta_id', $conta->id)->where('situacao', 'confirmada')->where('data_efetiva', '<=', $data)->sum('valor'), 2);
    }

    private function cartao(int $usuario, int $cartao): object
    {
        $registro = DB::table('contas')->where('usuario_id', $usuario)->where('id', $cartao)->first();
        abort_unless($registro !== null && $registro->tipo === 'cartao_credito', 404);

        return $registro;
    }

    private function apresentar(object $fatura): array
    {
        $hoje = CarbonImmutable::today(config('app.timezone'));

        return [
            'id' => $fatura->id, 'cartao_id' => $fatura->cartao_id, 'conta_pagamento_id' => $fatura->conta_pagamento_id,
            'inicio_periodo' => $fatura->inicio_periodo, 'fim_periodo' => $fatura->fim_periodo,
            'fechamento' => $fatura->fechamento, 'vencimento' => $fatura->vencimento,
            'valor_total' => Entrada::moeda((string) $fatura->valor_total), 'situacao' => $fatura->situacao,
            'transacao_pagamento_id' => $fatura->transacao_pagamento_id, 'paga_em' => $fatura->paga_em,
            'pagamento_externo' => $fatura->situacao === 'paga' && $fatura->transacao_cartao_id !== null && $fatura->transacao_pagamento_id === null,
            'correcao_permitida' => $this->correcaoPermitida($fatura),
            'fechavel' => $this->fechamentoAntecipadoPermitido($fatura, $hoje),
        ];
    }
}
