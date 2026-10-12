<?php

namespace App\Modulos\Financeiro\Cartoes;

use App\Modulos\Financeiro\Suporte\IdentificadorPublico;
use Carbon\CarbonImmutable;

final class ParceladorComprasCartao
{
    /** @return array<int, array{data: string, valor: string, numero: int, total: int, compra_parcelada_id: ?string}> */
    public function dividir(string $valorTotal, string $dataInicial, int $quantidade): array
    {
        if ($quantidade === 1) {
            return [[
                'data' => $dataInicial, 'valor' => $valorTotal, 'numero' => 1,
                'total' => 1, 'compra_parcelada_id' => null,
            ]];
        }
        $totalCentavos = (int) bcmul(bcsub('0', $valorTotal, 2), '100', 0);
        $valorBase = intdiv($totalCentavos, $quantidade);
        $centavosRestantes = $totalCentavos % $quantidade;
        $data = CarbonImmutable::parse($dataInicial);
        $grupo = IdentificadorPublico::novo();
        $parcelas = [];

        for ($indice = 0; $indice < $quantidade; $indice++) {
            $centavos = $valorBase + ($indice < $centavosRestantes ? 1 : 0);
            $parcelas[] = [
                'data' => $data->addMonthsNoOverflow($indice)->toDateString(),
                'valor' => bcdiv((string) -$centavos, '100', 2),
                'numero' => $indice + 1,
                'total' => $quantidade,
                'compra_parcelada_id' => $grupo,
            ];
        }

        return $parcelas;
    }
}
