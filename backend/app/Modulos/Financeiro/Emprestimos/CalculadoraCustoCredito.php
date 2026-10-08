<?php

namespace App\Modulos\Financeiro\Emprestimos;

final class CalculadoraCustoCredito
{
    public function calcular(string $valorRecebido, string $totalPrevisto): array
    {
        $recebido = AritmeticaMonetaria::centavos($valorRecebido);
        $total = AritmeticaMonetaria::centavos($totalPrevisto);
        $custo = $total - $recebido;
        $percentual = $recebido > 0
            ? bcmul(bcsub(bcdiv(AritmeticaMonetaria::decimal($total), AritmeticaMonetaria::decimal($recebido), 10), '1', 10), '100', 6)
            : null;

        return [
            'total_previsto' => AritmeticaMonetaria::decimal($total),
            'custo_total_credito' => AritmeticaMonetaria::decimal($custo),
            'percentual_custo_sobre_valor_recebido' => $percentual,
        ];
    }
}
