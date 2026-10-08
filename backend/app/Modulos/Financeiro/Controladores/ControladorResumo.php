<?php

namespace App\Modulos\Financeiro\Controladores;

use App\Http\Controllers\Controller;
use App\Modulos\Financeiro\Resumo\ServicoResumoFinanceiro;
use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ControladorResumo extends Controller
{
    public function __construct(private readonly ServicoResumoFinanceiro $resumos) {}

    public function mostrar(Request $requisicao): JsonResponse
    {
        $dados = Entrada::validar($requisicao, [
            'inicio' => ['required', 'date_format:Y-m-d'],
            'fim' => ['required', 'date_format:Y-m-d', 'after_or_equal:inicio'],
            'conta_id' => ['sometimes', 'integer', 'min:1'],
            'moeda' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
        ]);

        return response()->json($this->resumos->calcular(Entrada::usuario($requisicao), $dados));
    }
}
