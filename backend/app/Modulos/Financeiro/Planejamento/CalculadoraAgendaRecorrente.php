<?php

namespace App\Modulos\Financeiro\Planejamento;

use Carbon\CarbonImmutable;

final class CalculadoraAgendaRecorrente
{
    public function proximo(object $pagamento): ?string
    {
        $atual = CarbonImmutable::parse($pagamento->proximo_vencimento);
        $base = CarbonImmutable::parse($pagamento->primeiro_vencimento);
        $proximo = $this->seguinte($pagamento->periodicidade, $atual, $base);

        return $this->dentroDaVigencia($pagamento, $proximo) ? $proximo->toDateString() : null;
    }

    public function quantidadeNoPeriodo(object $pagamento, string $inicio, string $fim): int
    {
        return count($this->datasNoPeriodo($pagamento, $inicio, $fim));
    }

    /** @return list<string> */
    public function datasNoPeriodo(object $pagamento, string $inicio, string $fim): array
    {
        if ($pagamento->situacao !== 'ativo' || $pagamento->proximo_vencimento === null) {
            return [];
        }
        $atual = CarbonImmutable::parse($pagamento->proximo_vencimento);
        $base = CarbonImmutable::parse($pagamento->primeiro_vencimento);
        $inicioPeriodo = CarbonImmutable::parse($inicio);
        $fimPeriodo = CarbonImmutable::parse($fim);
        while ($atual->isBefore($inicioPeriodo) && $this->dentroDaVigencia($pagamento, $atual)) {
            $atual = $this->seguinte($pagamento->periodicidade, $atual, $base);
        }
        $datas = [];
        while (! $atual->isAfter($fimPeriodo) && $this->dentroDaVigencia($pagamento, $atual)) {
            $datas[] = $atual->toDateString();
            $atual = $this->seguinte($pagamento->periodicidade, $atual, $base);
        }

        return $datas;
    }

    private function seguinte(string $periodicidade, CarbonImmutable $atual, CarbonImmutable $base): CarbonImmutable
    {
        return match ($periodicidade) {
            'semanal' => $atual->addWeek(),
            'mensal' => $this->dataMensal($atual, $base->day),
            'anual' => $this->dataAnual($atual, $base->month, $base->day),
        };
    }

    private function dataMensal(CarbonImmutable $atual, int $dia): CarbonImmutable
    {
        $mes = $atual->startOfMonth()->addMonth();

        return $mes->day(min($dia, $mes->daysInMonth));
    }

    private function dataAnual(CarbonImmutable $atual, int $mes, int $dia): CarbonImmutable
    {
        $ano = CarbonImmutable::create($atual->year + 1, $mes, 1);

        return $ano->day(min($dia, $ano->daysInMonth));
    }

    private function dentroDaVigencia(object $pagamento, CarbonImmutable $data): bool
    {
        return $pagamento->data_fim === null || ! $data->isAfter($pagamento->data_fim);
    }
}
