<?php

namespace App\Modulos\Financeiro\Emprestimos;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class ClassificadorParcelas
{
    public function naoSeAplica(object $emprestimo, object $parcela): bool
    {
        return $parcela->situacao === 'pendente'
            && $parcela->vencimento < CarbonImmutable::parse($emprestimo->created_at)->toDateString();
    }

    public function pendentes(object $emprestimo, Collection $parcelas): Collection
    {
        return $parcelas->where('situacao', 'pendente')
            ->reject(fn (object $parcela) => $this->naoSeAplica($emprestimo, $parcela));
    }

    public function situacaoExibicao(object $emprestimo, object $parcela): string
    {
        if ($this->naoSeAplica($emprestimo, $parcela)) {
            return 'nao_se_aplica';
        }

        return $parcela->situacao === 'pendente' && $parcela->vencimento < now()->toDateString()
            ? 'atrasada'
            : $parcela->situacao;
    }
}
