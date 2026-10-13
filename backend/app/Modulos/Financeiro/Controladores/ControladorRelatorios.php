<?php

namespace App\Modulos\Financeiro\Controladores;

use App\Http\Controllers\Controller;
use App\Modulos\Financeiro\Relatorios\ServicoRelatoriosFinanceiros;
use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ControladorRelatorios extends Controller
{
    public function __construct(private readonly ServicoRelatoriosFinanceiros $relatorios) {}

    public function evolucao(Request $requisicao): JsonResponse
    {
        $dados = Entrada::validar($requisicao, [
            'intervalo' => ['required', 'in:30d,6m,12m'],
            'referencia' => ['required', 'date_format:Y-m-d'],
            'moeda' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
        ]);

        return response()->json($this->relatorios->evolucao(Entrada::usuario($requisicao), $dados));
    }

    public function comparacaoMensal(Request $requisicao): JsonResponse
    {
        $dados = Entrada::validar($requisicao, [
            'mes_a' => ['required', 'date_format:Y-m'], 'mes_b' => ['required', 'date_format:Y-m'],
            'moeda' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
        ]);

        return response()->json($this->relatorios->comparar(Entrada::usuario($requisicao), $dados));
    }
}
