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
            'transacao_id' => ['nullable', 'integer', 'min:1'],
            'codigo_movimentacao' => ['nullable', 'string', 'regex:/^#?[1-9][0-9]{8}$/'],
        ]);
        if (isset($dados['transacao_id'], $dados['codigo_movimentacao'])) {
            throw ValidationException::withMessages([
                'codigo_movimentacao' => 'Informe somente o código da movimentação.',
            ]);
        }

        return response()->json(['fatura' => $this->faturas->pagar(
            Entrada::usuario($requisicao), $id, $faturaId, $dados['data'], $dados['transacao_id'] ?? null,
            isset($dados['codigo_movimentacao']) ? Codigo::normalizar($dados['codigo_movimentacao']) : null,
        )]);
    }
}
