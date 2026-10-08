<?php

namespace App\Modulos\Financeiro\Emprestimos;

final class SimuladorQuitacao
{
    public function simular(array $parcelasPendentes, ?string $valorInformado): array
    {
        $nominal = array_reduce(
            $parcelasPendentes,
            fn (int $total, object|array $parcela) => $total + AritmeticaMonetaria::centavos((string) (is_array($parcela) ? $parcela['valor_total'] : $parcela->valor_total)),
            0,
        );
        $saldo = array_reduce(
            $parcelasPendentes,
            fn (int $total, object|array $parcela) => $total + AritmeticaMonetaria::centavos(is_array($parcela)
                ? bcadd((string) $parcela['principal'], (string) $parcela['taxas'], 2)
                : bcadd((string) $parcela->principal, (string) $parcela->taxas, 2)),
            0,
        );
        $informadoPeloBanco = $valorInformado !== null;
        $quitacao = $informadoPeloBanco ? AritmeticaMonetaria::centavos($valorInformado) : $saldo;
        $economia = max(0, $nominal - $quitacao);
        $percentual = $nominal > 0
            ? bcdiv(bcmul(AritmeticaMonetaria::decimal($economia), '100', 6), AritmeticaMonetaria::decimal($nominal), 6)
            : '0.000000';

        return [
            'parcelas_restantes' => count($parcelasPendentes),
            'soma_nominal_parcelas_restantes' => AritmeticaMonetaria::decimal($nominal),
            'valor_quitacao' => AritmeticaMonetaria::decimal($quitacao),
            'economia_estimada' => AritmeticaMonetaria::decimal($economia),
            'percentual_economia' => $percentual,
            'origem_valor_quitacao' => $informadoPeloBanco ? 'informado_pelo_banco' : 'estimativa_life_finance',
            'aviso' => $informadoPeloBanco
                ? 'Simulação baseada no valor de quitação informado pela instituição financeira.'
                : 'Estimativa do Life Finance. Consulte a instituição para obter o valor oficial de quitação.',
        ];
    }
}
