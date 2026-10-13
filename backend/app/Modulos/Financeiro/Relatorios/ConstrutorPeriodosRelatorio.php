<?php

namespace App\Modulos\Financeiro\Relatorios;

use Carbon\CarbonImmutable;

final class ConstrutorPeriodosRelatorio
{
    public function evolucao(string $intervalo, string $referencia): array
    {
        $data = CarbonImmutable::parse($referencia);

        return $intervalo === '30d'
            ? $this->dias($data->subDays(29), $data)
            : $this->meses($data, $intervalo === '6m' ? 6 : 12);
    }

    public function mes(string $mes): array
    {
        $inicio = CarbonImmutable::createFromFormat('!Y-m', $mes)->startOfMonth();

        return $this->periodo($inicio, $inicio->endOfMonth(), 'mensal');
    }

    private function dias(CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $periodos = [];
        for ($data = $inicio; $data->lte($fim); $data = $data->addDay()) {
            $periodos[] = $this->item($data, $data, 'diaria');
        }

        return $this->periodo($inicio, $fim, 'diaria', $periodos);
    }

    private function meses(CarbonImmutable $referencia, int $quantidade): array
    {
        $fim = $referencia->endOfMonth();
        $inicio = $referencia->startOfMonth()->subMonths($quantidade - 1);
        $periodos = [];
        for ($data = $inicio; $data->lte($fim); $data = $data->addMonth()) {
            $periodos[] = $this->item($data->startOfMonth(), $data->endOfMonth(), 'mensal');
        }

        return $this->periodo($inicio, $fim, 'mensal', $periodos);
    }

    private function periodo(CarbonImmutable $inicio, CarbonImmutable $fim, string $granularidade, ?array $periodos = null): array
    {
        return [
            'inicio' => $inicio->toDateString(), 'fim' => $fim->toDateString(),
            'granularidade' => $granularidade, 'periodos' => $periodos ?? [$this->item($inicio, $fim, $granularidade)],
        ];
    }

    private function item(CarbonImmutable $inicio, CarbonImmutable $fim, string $granularidade): array
    {
        $chave = $granularidade === 'diaria' ? $inicio->toDateString() : $inicio->format('Y-m');
        $rotulo = $granularidade === 'diaria' ? $inicio->format('d/m') : $inicio->format('m/Y');

        return ['chave' => $chave, 'rotulo' => $rotulo, 'inicio' => $inicio->toDateString(), 'fim' => $fim->toDateString()];
    }
}
