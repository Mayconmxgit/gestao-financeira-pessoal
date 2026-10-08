<?php

namespace App\Modulos\Assinaturas\Controladores;

use App\Http\Controllers\Controller;
use App\Modulos\Assinaturas\Servicos\ServicoAssinaturas;
use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ControladorAssinaturas extends Controller
{
    public function planos(ServicoAssinaturas $servico): JsonResponse
    {
        return response()->json(['dados' => $servico->listarPlanosAtivos()]);
    }

    public function atual(Request $requisicao, ServicoAssinaturas $servico): JsonResponse
    {
        return response()->json(['assinatura' => $servico->assinaturaAtual(Entrada::usuario($requisicao))]);
    }

    public function checkout(Request $requisicao, ServicoAssinaturas $servico): JsonResponse
    {
        $dados = Entrada::validar($requisicao, ['plano_id' => ['required', 'uuid']]);
        $resultado = $servico->criarCheckout($requisicao->attributes->get('usuario_atual'), $dados['plano_id']);

        return response()->json($resultado, 201);
    }
}
