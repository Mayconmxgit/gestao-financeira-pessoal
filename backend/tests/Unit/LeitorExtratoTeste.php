<?php

namespace Tests\Unit;

use App\Modulos\Financeiro\Importacao\ExtratorPdfItau;
use App\Modulos\Financeiro\Importacao\LeitorExtrato;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class LeitorExtratoTeste extends TestCase
{
    public function test_ofx_sgml_e_csv_brasileiro_geram_valores_exatos(): void
    {
        $ofx = '<OFX><BANKID>123<STMTTRN><DTPOSTED>20260905120000<TRNAMT>-120.40<FITID>abc<NAME>Mercado</STMTTRN></BANKTRANLIST></OFX>';
        $resultado = (new LeitorExtrato)->ler($ofx, 'ofx');
        $this->assertSame('123', $resultado['instituicao']);
        $this->assertSame('2026-09-05', $resultado['linhas'][0]['data_efetiva']);
        $this->assertSame('-120.40', $resultado['linhas'][0]['valor']);
        $this->assertSame('abc', $resultado['linhas'][0]['identificador_bancario']);

        $csv = "data;descricao;valor\n05/09/2026;Pagamento;1.234,56\n";
        $linha = (new LeitorExtrato)->ler($csv, 'csv')['linhas'][0];
        $this->assertSame('1234.56', $linha['valor']);
    }

    public function test_linha_com_data_impossivel_permanece_invalida_na_previa(): void
    {
        $csv = "data,descricao,valor\n2026-02-30,Mercado,-50.00\n";
        $linha = (new LeitorExtrato)->ler($csv, 'csv')['linhas'][0];
        $this->assertNull($linha['data_efetiva']);
        $this->assertSame('LINHA_INVALIDA', $linha['erro']);
    }

    public function test_csv_itau_reconhece_cabecalhos_das_duas_exportacoes(): void
    {
        $leitor = new LeitorExtrato;
        $variantes = [
            "\xEF\xBB\xBFData,Lançamentos,Valor (R$),Saldo (R$)\n18/09/2026,PIX recebido,\"1.235,40\",\"2.000,00\"\n18/09/2026,Saldo do dia,,\"2.000,00\"\n",
            "\xEF\xBB\xBFdata;lancamento;valor_R$;saldo_R$\n18/09/2026;Pagamento;-35,20;1.964,80\n18/09/2026;Saldo do dia;;1.964,80\n",
        ];

        foreach ($variantes as $csv) {
            $resultado = $leitor->ler($csv, 'csv');
            $this->assertSame('csv_padrao', $resultado['adaptador']);
            $this->assertSame(2, $resultado['versao']);
            $this->assertCount(1, $resultado['linhas']);
            $this->assertNull($resultado['linhas'][0]['erro']);
        }
        $this->assertSame('1235.40', $leitor->ler($variantes[0], 'csv')['linhas'][0]['valor']);
        $this->assertSame('-35.20', $leitor->ler($variantes[1], 'csv')['linhas'][0]['valor']);
    }

    public function test_pdf_itau_extrai_lancamentos_e_descarta_saldos_diarios(): void
    {
        $texto = "Itaú\nextrato conta / lançamentos\ndata lançamentos valor (R$) saldo (R$)\n"
            ."05/07/2026PAY MERCADO 05/07\t-10,50\n"
            ."05/07/2026SALDO DO DIA\t120,00\n"
            ."04/07/2026PIX TRANSF CLIENTE 04/07\t1.234,56\n";

        $linhas = (new ExtratorPdfItau)->extrairTexto($texto);

        $this->assertCount(2, $linhas);
        $this->assertSame('05/07/2026', $linhas[0]['data']);
        $this->assertSame('PAY MERCADO 05/07', $linhas[0]['descricao']);
        $this->assertSame('-10,50', $linhas[0]['valor']);
        $this->assertSame('1.234,56', $linhas[1]['valor']);
    }

    public function test_pdf_sem_layout_itau_e_rejeitado(): void
    {
        $this->expectException(ValidationException::class);

        (new ExtratorPdfItau)->extrairTexto("Documento qualquer\n05/07/2026 Compra -10,00");
    }
}
