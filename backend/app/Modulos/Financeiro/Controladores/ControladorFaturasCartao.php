<?php

namespace App\Modulos\Financeiro\Controladores;

use App\Http\Controllers\Controller;
use App\Modulos\Financeiro\Cartoes\ServicoFaturasCartao;
use App\Modulos\Financeiro\Suporte\CodigoMovimentacao as Codigo;
use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ControladorFaturasCartao extends Controller
{
    public function __construct(private readonly ServicoFaturasCartao $faturas) {}

    public function listar(Request $requisicao, int $id): JsonResponse
    {
        return response()->json(['faturas' => $this->faturas->listar(Entrada::usuario($requisicao), $id)]);
    }

    public function mostrar(Request $requisicao, int $id, int $faturaId): JsonResponse
    {
        return response()->json(['fatura' => $this->faturas->mostrar(Entrada::usuario($requisicao), $id, $faturaId)]);
    }

    public function pagar(Request $requisicao, int $id, int $faturaId): JsonResponse
    {
        $dados = Entrada::validar($requisicao, [
            'data' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'pagamento_externo' => ['sometimes', 'boolean'],
            'conta_pagamento_id' => ['required_unless:pagamento_externo,true', 'prohibited_if:pagamento_externo,true', 'nullable', 'integer', 'min:1'],
            'transacao_id' => ['prohibited_if:pagamento_externo,true', 'nullable', 'integer', 'min:1'],
            'codigo_movimentacao' => ['prohibited_if:pagamento_externo,true', 'nullable', 'string', 'regex:/^#?[1-9][0-9]{8}$/'],
        ]);
        if (isset($dados['transacao_id'], $dados['codigo_movimentacao'])) {
            throw ValidationException::withMessages([
                'codigo_movimentacao' => 'Informe somente o código da movimentação.',
            ]);
        }

        return response()->json(['fatura' => $this->faturas->pagar(
            Entrada::usuario($requisicao), $id, $faturaId, isset($dados['conta_pagamento_id']) ? (int) $dados['conta_pagamento_id'] : null,
            $dados['data'], $dados['transacao_id'] ?? null,
            isset($dados['codigo_movimentacao']) ? Codigo::normalizar($dados['codigo_movimentacao']) : null,
            (bool) ($dados['pagamento_externo'] ?? false),
        )]);
    }

    public function adiantar(Request $requisicao, int $id, int $faturaId): JsonResponse
    {
        $dados = Entrada::validar($requisicao, [
            'data' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'conta_pagamento_id' => ['required', 'integer', 'min:1'],
            'valor' => ['required', 'regex:/^[0-9]{1,15}(?:\.[0-9]{1,2})?$/'],
        ]);

        return response()->json(['fatura' => $this->faturas->adiantar(
            Entrada::usuario($requisicao), $id, $faturaId, (int) $dados['conta_pagamento_id'],
            $dados['data'], $dados['valor'],
        )]);
    }

    public function fecharAgora(Request $requisicao, int $id, int $faturaId): JsonResponse
    {
        Entrada::validar($requisicao, []);

        return response()->json(['fatura' => $this->faturas->fecharAgora(
            Entrada::usuario($requisicao), $id, $faturaId,
        )]);
    }

    public function corrigirLancamento(Request $requisicao, int $id, int $faturaId, int $lancamentoId): JsonResponse
    {
        $dados = Entrada::validar($requisicao, [
            'valor' => ['required', 'regex:/^[0-9]{1,15}(?:\.[0-9]{1,2})?$/'],
        ]);

        return response()->json(['fatura' => $this->faturas->corrigirLancamento(
            Entrada::usuario($requisicao), $id, $faturaId, $lancamentoId, $dados['valor'],
        )]);
    }

    public function cancelarLancamento(Request $requisicao, int $id, int $faturaId, int $lancamentoId): JsonResponse
    {
        $dados = Entrada::validar($requisicao, [
            'motivo' => ['required', 'string', 'min:5', 'max:300'],
        ]);

        return response()->json(['fatura' => $this->faturas->cancelarLancamento(
            Entrada::usuario($requisicao), $id, $faturaId, $lancamentoId, trim($dados['motivo']),
        )]);
    }
}
