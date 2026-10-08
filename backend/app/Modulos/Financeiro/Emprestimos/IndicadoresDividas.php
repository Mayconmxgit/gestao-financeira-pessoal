<?php

namespace App\Modulos\Financeiro\Emprestimos;

final class IndicadoresDividas
{
    public function __construct(
        private readonly RepositorioEmprestimos $repositorio,
        private readonly ClassificadorParcelas $classificador,
    ) {}

    public function calcular(int $usuario, ?string $moeda, string $receitaPeriodo): array
    {
        $contratos = $this->repositorio->ativos($usuario, $moeda);
        $nominal = $saldo = $juros = $custo = $parcelasMensais = '0.00';
        foreach ($contratos as $contrato) {
            $parcelas = $this->repositorio->parcelas($contrato->id);
            $pendentes = $this->classificador->pendentes($contrato, $parcelas);
            $nominal = $pendentes->reduce(fn (string $total, object $parcela) => bcadd($total, (string) $parcela->valor_total, 2), $nominal);
            $saldo = $pendentes->reduce(fn (string $total, object $parcela) => bcadd($total, bcadd((string) $parcela->principal, (string) $parcela->taxas, 2), 2), $saldo);
            $juros = $pendentes->reduce(fn (string $total, object $parcela) => bcadd($total, (string) $parcela->juros, 2), $juros);
            $proxima = $pendentes->sortBy('vencimento')->first();
            if ($proxima !== null) {
                $parcelasMensais = bcadd($parcelasMensais, (string) $proxima->valor_total, 2);
            }
            $recebido = (string) ($contrato->valor_recebido ?? $contrato->valor_principal);
            $total = $contrato->total_contratado ?? $parcelas->reduce(
                fn (string $soma, object $parcela) => bcadd($soma, (string) $parcela->valor_total, 2),
                '0.00',
            );
            $custoContrato = bcsub((string) $total, $recebido, 2);
            $custo = bcadd($custo, bccomp($custoContrato, '0', 2) > 0 ? $custoContrato : '0.00', 2);
        }
        $percentualRenda = bccomp($receitaPeriodo, '0', 2) > 0
            ? bcdiv(bcmul($parcelasMensais, '100', 4), $receitaPeriodo, 2)
            : null;

        return [
            'endividamento_total' => $nominal,
            'parcelas_mensais_dividas' => $parcelasMensais,
            'saldo_devedor_total' => $saldo,
            'juros_futuros_estimados' => $juros,
            'custo_total_dividas' => $custo,
            'percentual_renda_comprometida' => $percentualRenda,
            'quantidade_contratos_ativos' => $contratos->count(),
        ];
    }
}
