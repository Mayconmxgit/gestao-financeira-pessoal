<?php

namespace Tests\Unit;

use App\Modulos\Financeiro\Emprestimos\CalculadoraCustoCredito;
use App\Modulos\Financeiro\Emprestimos\CalculadoraParcelas;
use App\Modulos\Financeiro\Emprestimos\SimuladorQuitacao;
use PHPUnit\Framework\TestCase;

final class CalculadoraParcelasTeste extends TestCase
{
    public function test_price_reproduz_parcela_e_total_do_contrato_com_a_data_base_correta(): void
    {
        $parcelas = (new CalculadoraParcelas)->calcular([
            'valor_recebido' => '401.82', 'valor_financiado' => '412.37', 'iof' => '10.55',
            'taxa_juros_mensal' => '4.96', 'sistema_amortizacao' => 'price',
            'quantidade_parcelas' => 12, 'data_recebimento' => '2026-05-15',
            'data_base_calculo' => '2026-05-14',
            'primeiro_vencimento' => '2026-07-26',
            'valor_parcela_contratada' => '49.98',
        ]);

        $this->assertSame(['49.98'], array_values(array_unique(array_column($parcelas, 'valor_total'))));
        $this->assertSame('599.76', $this->somar($parcelas, 'valor_total'));
        $this->assertSame('401.82', $this->somar($parcelas, 'principal'));
        $this->assertSame('10.55', $this->somar($parcelas, 'taxas'));
        $this->assertSame('187.39', $this->somar($parcelas, 'juros'));
        $this->assertSame('412.37', $parcelas[0]['saldo_inicial']);
        $this->assertSame('0.00', $parcelas[11]['saldo_final']);
        $this->assertNotContains(true, array_map(fn (array $parcela) => bccomp($parcela['saldo_final'], '0', 2) < 0, $parcelas));
    }

    public function test_formula_price_mensal_calcula_parcela_teorica_sem_float(): void
    {
        $this->assertSame('46.42', (new CalculadoraParcelas)->calcularParcelaPrice('412.37', '4.96', 12));
    }

    public function test_juros_amortizacao_e_saldo_seguem_a_composicao_da_parcela(): void
    {
        $parcelas = (new CalculadoraParcelas)->calcular([
            'valor_recebido' => '1000.00', 'valor_financiado' => '1000.00',
            'taxa_juros_mensal' => '2.00', 'sistema_amortizacao' => 'sac',
            'quantidade_parcelas' => 2, 'primeiro_vencimento' => '2026-02-10',
        ]);

        $this->assertSame('20.00', $parcelas[0]['juros']);
        $this->assertSame('500.00', $parcelas[0]['principal']);
        $this->assertSame('500.00', $parcelas[0]['saldo_final']);
        $this->assertSame('510.00', $parcelas[1]['valor_total']);
    }

    public function test_custo_total_e_percentual_nao_sao_rotulados_como_juros(): void
    {
        $resultado = (new CalculadoraCustoCredito)->calcular('401.82', '599.76');

        $this->assertSame('599.76', $resultado['total_previsto']);
        $this->assertSame('197.94', $resultado['custo_total_credito']);
        $this->assertSame('49.260863', $resultado['percentual_custo_sobre_valor_recebido']);
    }

    public function test_simulacao_de_quitacao_distingue_valor_bancario_de_estimativa(): void
    {
        $parcelas = array_fill(0, 8, (object) ['valor_total' => '49.98', 'principal' => '39.00', 'taxas' => '1.00']);
        $resultado = (new SimuladorQuitacao)->simular($parcelas, '325.00');

        $this->assertSame(8, $resultado['parcelas_restantes']);
        $this->assertSame('399.84', $resultado['soma_nominal_parcelas_restantes']);
        $this->assertSame('74.84', $resultado['economia_estimada']);
        $this->assertSame('informado_pelo_banco', $resultado['origem_valor_quitacao']);

        $estimativa = (new SimuladorQuitacao)->simular($parcelas, null);
        $this->assertSame('320.00', $estimativa['valor_quitacao']);
        $this->assertSame('estimativa_life_finance', $estimativa['origem_valor_quitacao']);
    }

    public function test_sac_preserva_principal_e_reduz_juros(): void
    {
        $parcelas = (new CalculadoraParcelas)->calcular([
            'valor_principal' => '1000.00', 'taxas_totais' => '10.00',
            'taxa_juros_mensal' => '1.00', 'sistema_amortizacao' => 'sac',
            'quantidade_parcelas' => 3, 'primeiro_vencimento' => '2026-01-31',
        ]);

        $this->assertSame('1000.00', array_reduce($parcelas, fn ($total, $item) => bcadd($total, $item['principal'], 2), '0.00'));
        $this->assertSame('10.00', array_reduce($parcelas, fn ($total, $item) => bcadd($total, $item['taxas'], 2), '0.00'));
        $this->assertGreaterThan($parcelas[2]['juros'], $parcelas[0]['juros']);
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], array_column($parcelas, 'vencimento'));
    }

    public function test_price_sem_taxa_nao_cria_juros_de_arredondamento(): void
    {
        $parcelas = (new CalculadoraParcelas)->calcular([
            'valor_principal' => '1000.01', 'taxas_totais' => '12.02',
            'taxa_juros_mensal' => '0', 'sistema_amortizacao' => 'price',
            'quantidade_parcelas' => 7, 'data_recebimento' => '2026-01-10',
            'primeiro_vencimento' => '2026-02-10',
        ]);

        $this->assertSame('0.00', $this->somar($parcelas, 'juros'));
        $this->assertSame('1012.03', $this->somar($parcelas, 'valor_total'));
    }

    public function test_price_e_manual_corrigem_centavos_na_ultima_parcela(): void
    {
        $calculadora = new CalculadoraParcelas;
        foreach ([
            ['sistema_amortizacao' => 'price', 'taxa_juros_mensal' => '1.75'],
            ['sistema_amortizacao' => 'manual', 'taxa_juros_mensal' => '0', 'valor_total_manual' => '1200.00'],
        ] as $modo) {
            $parcelas = $calculadora->calcular($modo + [
                'valor_principal' => '1000.01', 'taxas_totais' => '12.02',
                'quantidade_parcelas' => 7, 'primeiro_vencimento' => '2026-02-10',
            ]);
            $this->assertSame('1000.01', array_reduce($parcelas, fn ($total, $item) => bcadd($total, $item['principal'], 2), '0.00'));
            $this->assertSame('12.02', array_reduce($parcelas, fn ($total, $item) => bcadd($total, $item['taxas'], 2), '0.00'));
        }
    }

    public function test_total_manual_nao_e_substituido_pelo_total_apenas_informativo(): void
    {
        $parcelas = (new CalculadoraParcelas)->calcular([
            'valor_principal' => '1000.00', 'taxas_totais' => '0.00',
            'taxa_juros_mensal' => '0', 'sistema_amortizacao' => 'manual',
            'quantidade_parcelas' => 3, 'primeiro_vencimento' => '2026-02-10',
            'valor_total_manual' => '1200.00', 'total_contratado' => '1500.00',
        ]);

        $this->assertSame('1200.00', $this->somar($parcelas, 'valor_total'));
    }

    private function somar(array $parcelas, string $campo): string
    {
        return array_reduce($parcelas, fn ($total, $item) => bcadd($total, $item[$campo], 2), '0.00');
    }
}
