<?php

namespace App\Modulos\Financeiro\Emprestimos;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class CalculadoraParcelas
{
    public function calcular(array $dados): array
    {
        $principal = AritmeticaMonetaria::centavos($dados['valor_recebido'] ?? $dados['valor_principal']);
        $financiado = AritmeticaMonetaria::centavos(
            $dados['valor_financiado'] ?? AritmeticaMonetaria::decimal(
                $principal + AritmeticaMonetaria::centavos($dados['taxas_totais'] ?? $dados['iof'] ?? '0')
            )
        );
        $custosFinanciados = $financiado - $principal;
        $quantidade = (int) $dados['quantidade_parcelas'];
        $taxa = (string) $dados['taxa_juros_mensal'];
        $parcelaContratada = isset($dados['valor_parcela_contratada']) && $dados['valor_parcela_contratada'] !== null
            ? AritmeticaMonetaria::centavos($dados['valor_parcela_contratada'])
            : null;

        $parcelas = match ($dados['sistema_amortizacao']) {
            'price' => $this->price(
                $principal,
                $custosFinanciados,
                $taxa,
                $quantidade,
                $dados['data_base_calculo'] ?? $dados['data_recebimento'] ?? $dados['data_contratacao'] ?? null,
                $dados['primeiro_vencimento'],
                $parcelaContratada,
            ),
            'sac' => $this->sac($principal, $custosFinanciados, $taxa, $quantidade),
            'manual' => $this->manual(
                $principal,
                $custosFinanciados,
                AritmeticaMonetaria::centavos($dados['valor_total_manual'] ?? $dados['total_contratado']),
                $quantidade,
            ),
        };

        return $this->adicionarVencimentos($parcelas, $dados['primeiro_vencimento']);
    }

    public function calcularParcelaPrice(string $valorFinanciado, string $taxaPercentual, int $quantidade): string
    {
        $financiado = AritmeticaMonetaria::centavos($valorFinanciado);
        if ($quantidade < 1) {
            throw new \InvalidArgumentException('A quantidade de parcelas deve ser positiva.');
        }
        $taxa = AritmeticaMonetaria::taxaDecimal($taxaPercentual);
        if (bccomp($taxa, '0', AritmeticaMonetaria::ESCALA) === 0) {
            return AritmeticaMonetaria::decimal($this->parte($financiado, $quantidade, 1));
        }
        $fator = bcpow(bcadd('1', $taxa, AritmeticaMonetaria::ESCALA), (string) $quantidade, AritmeticaMonetaria::ESCALA);
        $coeficiente = bcdiv(
            bcmul($taxa, $fator, AritmeticaMonetaria::ESCALA),
            bcsub($fator, '1', AritmeticaMonetaria::ESCALA),
            AritmeticaMonetaria::ESCALA,
        );
        $prestacao = bcmul(AritmeticaMonetaria::decimal($financiado), $coeficiente, AritmeticaMonetaria::ESCALA);

        return AritmeticaMonetaria::decimal(AritmeticaMonetaria::arredondarCentavos($prestacao));
    }

    private function price(int $principal, int $custos, string $taxaPercentual, int $quantidade, ?string $dataBase, string $primeiroVencimento, ?int $parcelaContratada): array
    {
        $vencimentos = $this->vencimentos($quantidade, $primeiroVencimento);
        $base = $dataBase ? CarbonImmutable::parse($dataBase) : $vencimentos[0]->subDays(30);
        $taxaMensal = AritmeticaMonetaria::taxaDecimal($taxaPercentual);
        $taxaDiaria = bcsub(AritmeticaMonetaria::raiz(bcadd('1', $taxaMensal, AritmeticaMonetaria::ESCALA), 30), '1', AritmeticaMonetaria::ESCALA);
        $financiado = $principal + $custos;
        $prestacao = $parcelaContratada ?? $this->prestacaoPriceIrregular($financiado, $taxaDiaria, $base, $vencimentos);
        $saldoPrincipal = $principal;
        $saldoCustos = $custos;
        $dataAnterior = $base;
        $parcelas = [];

        for ($numero = 1; $numero <= $quantidade; $numero++) {
            $valorPrestacao = $parcelaContratada === null && bccomp($taxaMensal, '0', AritmeticaMonetaria::ESCALA) === 0
                ? $this->parte($financiado, $quantidade, $numero)
                : $prestacao;
            $saldoInicial = $saldoPrincipal + $saldoCustos;
            $dias = (int) $dataAnterior->diffInDays($vencimentos[$numero - 1]);
            $fator = bcsub(bcpow(bcadd('1', $taxaDiaria, AritmeticaMonetaria::ESCALA), (string) $dias, AritmeticaMonetaria::ESCALA), '1', AritmeticaMonetaria::ESCALA);
            $juros = AritmeticaMonetaria::arredondarCentavos(bcmul(AritmeticaMonetaria::decimal($saldoInicial), $fator, AritmeticaMonetaria::ESCALA));
            $amortizacao = $numero === $quantidade ? $saldoInicial : min($saldoInicial, $valorPrestacao - $juros);
            if ($numero === $quantidade) {
                // O ajuste final elimina resíduos de centavos sem alterar a parcela contratada.
                $juros = max(0, $valorPrestacao - $amortizacao);
            }
            [$amortizacaoPrincipal, $amortizacaoCustos] = $this->dividirAmortizacao($amortizacao, $saldoPrincipal, $saldoCustos, $numero === $quantidade);
            $valorParcela = $amortizacao + $juros;
            $saldoFinal = max(0, $saldoInicial - $amortizacao);
            $parcelas[] = $this->parcela($numero, $amortizacaoPrincipal, $juros, $amortizacaoCustos, $saldoInicial, $saldoFinal, $valorParcela);
            $saldoPrincipal -= $amortizacaoPrincipal;
            $saldoCustos -= $amortizacaoCustos;
            $dataAnterior = $vencimentos[$numero - 1];
        }

        return $parcelas;
    }

    private function prestacaoPriceIrregular(int $financiado, string $taxaDiaria, CarbonImmutable $base, array $vencimentos): int
    {
        if (bccomp($taxaDiaria, '0', AritmeticaMonetaria::ESCALA) === 0) {
            return $this->parte($financiado, count($vencimentos), 1);
        }
        $soma = '0';
        foreach ($vencimentos as $vencimento) {
            $dias = (int) $base->diffInDays($vencimento);
            $fator = bcpow(bcadd('1', $taxaDiaria, AritmeticaMonetaria::ESCALA), (string) $dias, AritmeticaMonetaria::ESCALA);
            $soma = bcadd($soma, bcdiv('1', $fator, AritmeticaMonetaria::ESCALA), AritmeticaMonetaria::ESCALA);
        }

        return AritmeticaMonetaria::arredondarCentavos(bcdiv(AritmeticaMonetaria::decimal($financiado), $soma, AritmeticaMonetaria::ESCALA));
    }

    private function dividirAmortizacao(int $total, int $saldoPrincipal, int $saldoCustos, bool $ultima): array
    {
        if ($ultima) {
            return [$saldoPrincipal, $saldoCustos];
        }
        if ($total < 0) {
            return [$total, 0];
        }
        $saldo = $saldoPrincipal + $saldoCustos;
        $custosProporcionais = $saldo === 0 ? 0 : AritmeticaMonetaria::arredondarCentavos(bcdiv(
            bcmul(AritmeticaMonetaria::decimal($total), AritmeticaMonetaria::decimal($saldoCustos), AritmeticaMonetaria::ESCALA),
            AritmeticaMonetaria::decimal($saldo),
            AritmeticaMonetaria::ESCALA,
        ));
        $custos = min($saldoCustos, $custosProporcionais);
        $principal = min($saldoPrincipal, $total - $custos);

        return [$principal, $custos + ($total - $custos - $principal)];
    }

    private function vencimentos(int $quantidade, string $primeiroVencimento): array
    {
        $primeiraData = CarbonImmutable::parse($primeiroVencimento);

        return array_map(fn (int $indice) => $primeiraData->addMonthsNoOverflow($indice), range(0, $quantidade - 1));
    }

    private function sac(int $principal, int $custos, string $taxaPercentual, int $quantidade): array
    {
        $saldoPrincipal = $principal;
        $saldoCustos = $custos;
        $taxa = AritmeticaMonetaria::taxaDecimal($taxaPercentual);
        $parcelas = [];
        for ($numero = 1; $numero <= $quantidade; $numero++) {
            $saldoInicial = $saldoPrincipal + $saldoCustos;
            $amortizacaoPrincipal = $this->parte($principal, $quantidade, $numero);
            $amortizacaoCustos = $this->parte($custos, $quantidade, $numero);
            $juros = AritmeticaMonetaria::arredondarCentavos(bcmul(AritmeticaMonetaria::decimal($saldoInicial), $taxa, AritmeticaMonetaria::ESCALA));
            $saldoPrincipal -= $amortizacaoPrincipal;
            $saldoCustos -= $amortizacaoCustos;
            $saldoFinal = max(0, $saldoPrincipal + $saldoCustos);
            $parcelas[] = $this->parcela($numero, $amortizacaoPrincipal, $juros, $amortizacaoCustos, $saldoInicial, $saldoFinal);
        }

        return $parcelas;
    }

    private function manual(int $principal, int $custos, int $total, int $quantidade): array
    {
        if ($total < $principal + $custos) {
            throw ValidationException::withMessages(['valor_total_manual' => 'O total não pode ser menor que o valor financiado.']);
        }
        $juros = $total - $principal - $custos;
        $saldo = $principal + $custos;

        return array_map(function (int $numero) use ($principal, $custos, $juros, $quantidade, &$saldo): array {
            $amortizacaoPrincipal = $this->parte($principal, $quantidade, $numero);
            $amortizacaoCustos = $this->parte($custos, $quantidade, $numero);
            $jurosParcela = $this->parte($juros, $quantidade, $numero);
            $saldoInicial = $saldo;
            $saldo = max(0, $saldo - $amortizacaoPrincipal - $amortizacaoCustos);

            return $this->parcela($numero, $amortizacaoPrincipal, $jurosParcela, $amortizacaoCustos, $saldoInicial, $saldo);
        }, range(1, $quantidade));
    }

    private function parte(int $total, int $quantidade, int $numero): int
    {
        $base = intdiv($total, $quantidade);

        return $base + ($numero <= $total % $quantidade ? 1 : 0);
    }

    private function parcela(int $numero, int $principal, int $juros, int $taxas, int $saldoInicial, int $saldoFinal, ?int $valorTotal = null): array
    {
        return compact('numero', 'principal', 'juros', 'taxas', 'saldoInicial', 'saldoFinal') + [
            'valor_total' => $valorTotal ?? $principal + $juros + $taxas,
        ];
    }

    private function adicionarVencimentos(array $parcelas, string $primeiroVencimento): array
    {
        $primeiraData = CarbonImmutable::parse($primeiroVencimento);

        return array_map(function (array $parcela) use ($primeiraData): array {
            $parcela['vencimento'] = $primeiraData->addMonthsNoOverflow($parcela['numero'] - 1)->toDateString();
            $parcela['saldo_inicial'] = AritmeticaMonetaria::decimal($parcela['saldoInicial']);
            $parcela['saldo_final'] = AritmeticaMonetaria::decimal($parcela['saldoFinal']);
            unset($parcela['saldoInicial'], $parcela['saldoFinal']);
            foreach (['principal', 'juros', 'taxas', 'valor_total'] as $campo) {
                $parcela[$campo] = AritmeticaMonetaria::decimal($parcela[$campo]);
            }

            return $parcela;
        }, $parcelas);
    }
}
