<?php

namespace Tests\Feature;

use App\Modulos\Financeiro\Cartoes\ServicoFaturasCartao;
use App\Modulos\Financeiro\Planejamento\ServicoPagamentosRecorrentes;
use App\Modulos\Usuarios\Modelos\Usuario;
use App\Modulos\Usuarios\Servicos\ServicoToken;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class FaturasCartaoTeste extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('autenticacao.segredo', base64_encode(random_bytes(32)));
        CarbonImmutable::setTestNow('2026-10-07 09:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_cartao_herda_banco_moeda_e_expoe_limite_e_fatura(): void
    {
        $token = $this->autenticar('cartao@exemplo.test');
        $conta = $this->conta($token, 'Conta principal', '2500.00', 'Banco Seguro');
        $cartao = $this->cartao($token, $conta, 'Cartão Black', '3000.00');
        $this->cartao($token, $conta, 'Cartão Virtual', '500.00');

        $this->withToken($token)->getJson('/api/v1/contas')->assertOk()
            ->assertJsonFragment([
                'id' => $cartao, 'nome' => 'Cartão Black', 'moeda' => 'BRL', 'saldo_inicial' => 0,
                'instituicao' => 'Banco Seguro', 'conta_pagamento_id' => $conta,
                'conta_pagamento_nome' => 'Conta principal', 'limite_credito' => 3000,
                'limite_utilizado' => '0.00', 'limite_disponivel' => '3000.00',
            ]);
        $this->assertDatabaseCount('faturas_cartao', 2);
        $this->assertDatabaseHas('auditoria_financeira', ['acao' => 'fatura_cartao_criada', 'entidade' => 'faturas_cartao']);
        $this->withToken($token)->patchJson("/api/v1/contas/$cartao", ['instituicao' => 'Outro banco'])
            ->assertUnprocessable()->assertJsonPath('erros.instituicao.0', 'A instituição do cartão é herdada da conta vinculada.');
    }

    public function test_compra_no_fechamento_vai_para_proxima_fatura_e_mes_curto_e_ajustado(): void
    {
        $token = $this->autenticar('ciclo@exemplo.test');
        $conta = $this->conta($token, 'Conta');
        $cartao = $this->cartao($token, $conta, 'Cartão', '1000.00', 10, 17);

        $this->compra($token, $cartao, '2026-10-09', '100.00');
        CarbonImmutable::setTestNow('2026-10-10 09:00:00');
        app(ServicoFaturasCartao::class)->fecharTodas('2026-10-10');
        $this->compra($token, $cartao, '2026-10-10', '50.00');

        $this->assertDatabaseHas('faturas_cartao', ['fechamento' => '2026-10-10', 'valor_total' => '100.00', 'situacao' => 'fechada']);
        $this->assertDatabaseHas('faturas_cartao', ['fechamento' => '2026-11-10', 'valor_total' => '50.00', 'situacao' => 'aberta']);

        CarbonImmutable::setTestNow('2027-02-01 09:00:00');
        $cartaoCurto = $this->cartao($token, $conta, 'Cartão 31', '500.00', 31, 5);
        $this->assertDatabaseHas('faturas_cartao', ['cartao_id' => $this->id('contas', $cartaoCurto), 'fechamento' => '2027-02-28', 'vencimento' => '2027-03-05']);
    }

    public function test_fechamento_repetido_e_idempotente(): void
    {
        $token = $this->autenticar('idempotencia@exemplo.test');
        $conta = $this->conta($token, 'Conta');
        $cartao = $this->cartao($token, $conta, 'Cartão', '1000.00');
        $this->compra($token, $cartao, '2026-10-07', '40.00');
        CarbonImmutable::setTestNow('2026-10-10 09:00:00');
        $servico = app(ServicoFaturasCartao::class);

        $this->assertSame(1, $servico->fecharTodas('2026-10-10'));
        $this->assertSame(0, $servico->fecharTodas('2026-10-10'));
        $this->assertSame(1, DB::table('faturas_cartao')->where('cartao_id', $this->id('contas', $cartao))
            ->where('fechamento', '2026-10-10')->count());
        $this->assertSame(1, DB::table('faturas_cartao')->where('cartao_id', $this->id('contas', $cartao))
            ->where('fechamento', '2026-11-10')->count());
    }

    public function test_limite_bloqueia_compra_e_reducao_abaixo_do_utilizado(): void
    {
        $token = $this->autenticar('limite@exemplo.test');
        $conta = $this->conta($token, 'Conta');
        $cartao = $this->cartao($token, $conta, 'Cartão', '100.00');
        $this->compra($token, $cartao, '2026-10-07', '80.00');

        $this->withToken($token)->postJson('/api/v1/transacoes', $this->dadosCompra($cartao, '2026-10-07', '30.00'))
            ->assertUnprocessable()->assertJsonPath('erros.valor.0', 'Limite insuficiente no cartão.');
        $this->withToken($token)->patchJson("/api/v1/contas/$cartao", ['limite_credito' => '79.99'])
            ->assertUnprocessable()->assertJsonPath('erros.limite_credito.0', 'O limite não pode ser menor que o valor utilizado.');
        $this->assertDatabaseCount('transacoes', 1);
    }

    public function test_cancelamento_de_compra_em_fatura_aberta_libera_limite_e_recalcula_fatura(): void
    {
        $token = $this->autenticar('cancelamento-cartao@exemplo.test');
        $conta = $this->conta($token, 'Conta');
        $cartao = $this->cartao($token, $conta, 'Cartão', '500.00');
        $compra = $this->compra($token, $cartao, '2026-10-07', '120.00');

        $this->withToken($token)->deleteJson("/api/v1/transacoes/$compra", [
            'motivo' => 'Compra lançada em duplicidade',
        ])->assertOk()->assertJsonPath('situacao', 'cancelada');

        $this->assertDatabaseHas('faturas_cartao', [
            'cartao_id' => $this->id('contas', $cartao), 'valor_total' => '0.00',
        ]);
        $this->withToken($token)->getJson('/api/v1/contas')->assertOk()
            ->assertJsonFragment(['id' => $cartao, 'limite_utilizado' => '0.00', 'limite_disponivel' => '500.00']);
    }

    public function test_compra_manual_parcelada_distribui_total_em_faturas_mensais(): void
    {
        $token = $this->autenticar('parcelada@exemplo.test');
        $conta = $this->conta($token, 'Conta');
        $cartao = $this->cartao($token, $conta, 'Cartão', '500.00');

        $resposta = $this->withToken($token)->postJson('/api/v1/transacoes', [
            ...$this->dadosCompra($cartao, '2026-10-07', '100.00'),
            'descricao' => 'Compra parcelada', 'quantidade_parcelas' => 3,
        ])->assertCreated()->assertJsonCount(3, 'parcelas_compra');

        $parcelas = $resposta->json('parcelas_compra');
        $this->assertSame([-33.34, -33.33, -33.33], array_column($parcelas, 'valor'));
        $this->assertSame(['2026-10-07', '2026-11-07', '2026-12-07'], array_column($parcelas, 'data_efetiva'));
        $this->assertSame([1, 2, 3], array_column($parcelas, 'numero_parcela'));
        $this->assertSame([3, 3, 3], array_column($parcelas, 'total_parcelas'));
        $this->assertCount(1, array_unique(array_column($parcelas, 'compra_parcelada_id')));
        $this->assertNotNull($parcelas[0]['compra_parcelada_id']);
        $this->assertDatabaseHas('faturas_cartao', ['fechamento' => '2026-10-10', 'valor_total' => '33.34']);
        $this->assertDatabaseHas('faturas_cartao', ['fechamento' => '2026-11-10', 'valor_total' => '33.33']);
        $this->assertDatabaseHas('faturas_cartao', ['fechamento' => '2026-12-10', 'valor_total' => '33.33']);
        $this->withToken($token)->getJson('/api/v1/contas')->assertOk()
            ->assertJsonFragment(['id' => $cartao, 'limite_utilizado' => '100.00', 'limite_disponivel' => '400.00']);
    }

    public function test_compra_parcelada_sem_limite_nao_grava_nenhuma_parcela(): void
    {
        $token = $this->autenticar('parcelada-limite@exemplo.test');
        $conta = $this->conta($token, 'Conta');
        $cartao = $this->cartao($token, $conta, 'Cartão', '99.99');

        $this->withToken($token)->postJson('/api/v1/transacoes', [
            ...$this->dadosCompra($cartao, '2026-10-07', '100.00'),
            'quantidade_parcelas' => 3,
        ])->assertUnprocessable()->assertJsonPath('erros.valor.0', 'Limite insuficiente no cartão.');

        $this->assertDatabaseCount('transacoes', 0);
        $this->assertDatabaseCount('faturas_cartao', 1);
    }

    public function test_parcelamento_manual_valida_tipo_de_conta_e_valor_minimo_por_parcela(): void
    {
        $token = $this->autenticar('parcelada-validacao@exemplo.test');
        $conta = $this->conta($token, 'Conta');
        $cartao = $this->cartao($token, $conta, 'Cartão', '100.00');

        $this->withToken($token)->postJson('/api/v1/transacoes', [
            'conta_id' => $conta, 'data' => '2026-10-07', 'valor' => '-10.00',
            'descricao' => 'Débito parcelado', 'natureza' => 'saida', 'quantidade_parcelas' => 2,
        ])->assertUnprocessable()->assertJsonPath('erros.quantidade_parcelas.0', 'Parcelamento disponível apenas para compras no cartão.');

        $this->withToken($token)->postJson('/api/v1/transacoes', [
            ...$this->dadosCompra($cartao, '2026-10-07', '0.01'), 'quantidade_parcelas' => 2,
        ])->assertUnprocessable()->assertJsonPath('erros.quantidade_parcelas.0', 'Cada parcela deve ter valor mínimo de R$ 0,01.');
        $this->assertDatabaseCount('transacoes', 0);
    }

    public function test_limite_bloqueia_importacao_sem_gravacao_parcial(): void
    {
        $token = $this->autenticar('importacao-limite@exemplo.test');
        $conta = $this->conta($token, 'Conta');
        $cartao = $this->cartao($token, $conta, 'Cartão', '50.00');
        $csv = "data,descricao,valor,id_bancario\n2026-10-07,Compra importada,-60.00,cartao-1\n";
        $lote = $this->withToken($token)->post('/api/v1/importacoes', [
            'conta_id' => $cartao, 'arquivo' => UploadedFile::fake()->createWithContent('cartao.csv', $csv),
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');

        $this->withToken($token)->postJson("/api/v1/importacoes/$lote/confirmacao", ['instituicao' => 'Itaú'])
            ->assertUnprocessable()->assertJsonPath('erros.valor.0', 'Limite insuficiente no cartão.');
        $this->assertDatabaseCount('transacoes', 0);
        $this->assertDatabaseHas('lotes_importacao', ['situacao' => 'previa']);
    }

    public function test_resumo_alerta_fechamento_fatura_fechada_e_vencida(): void
    {
        $token = $this->autenticar('alerta@exemplo.test');
        $conta = $this->conta($token, 'Conta');
        $cartao = $this->cartao($token, $conta, 'Cartão alerta', '1000.00');
        $this->compra($token, $cartao, '2026-10-07', '120.00');

        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-10-01&fim=2026-10-31')
            ->assertOk()->assertJsonPath('faturas_cartao.total', '120.00')
            ->assertJsonPath('faturas_cartao.itens.0.alerta', 'fechamento_proximo');

        CarbonImmutable::setTestNow('2026-10-10 09:00:00');
        app(ServicoFaturasCartao::class)->fecharTodas('2026-10-10');
        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-10-01&fim=2026-10-31')
            ->assertOk()->assertJsonPath('faturas_cartao.itens.0.alerta', 'fechada');

        CarbonImmutable::setTestNow('2026-10-18 09:00:00');
        app(ServicoFaturasCartao::class)->fecharTodas('2026-10-18');
        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-10-01&fim=2026-10-31')
            ->assertOk()->assertJsonPath('faturas_cartao.itens.0.alerta', 'vencida');
    }

    public function test_saldo_insuficiente_nao_grava_pagamento_e_fatura_permanece_pendente(): void
    {
        $token = $this->autenticar('saldo@exemplo.test');
        $conta = $this->conta($token, 'Conta baixa', '50.00');
        $cartao = $this->cartao($token, $conta, 'Cartão', '1000.00');
        $this->compra($token, $cartao, '2026-10-07', '100.00');
        CarbonImmutable::setTestNow('2026-10-10 09:00:00');
        app(ServicoFaturasCartao::class)->fecharTodas('2026-10-10');
        $fatura = (string) DB::table('faturas_cartao')->where('valor_total', '100.00')->value('uuid');
        $quantidade = DB::table('transacoes')->count();

        $this->withToken($token)->postJson("/api/v1/contas/$cartao/faturas/$fatura/pagamento", ['data' => '2026-10-10'])
            ->assertStatus(409)->assertJson([
                'codigo' => 'saldo_insuficiente', 'conta' => 'Conta baixa', 'saldo_disponivel' => '50.00',
                'valor_fatura' => '100.00', 'valor_faltante' => '50.00',
            ]);
        $this->assertSame($quantidade, DB::table('transacoes')->count());
        $this->assertDatabaseHas('faturas_cartao', ['uuid' => $fatura, 'situacao' => 'fechada', 'transacao_pagamento_id' => null]);
    }

    public function test_pagamento_integral_pode_criar_ou_vincular_debito_sem_duplicar_despesa(): void
    {
        $token = $this->autenticar('pagamento@exemplo.test');
        $conta = $this->conta($token, 'Conta', '1000.00');
        $cartao = $this->cartao($token, $conta, 'Cartão', '1000.00');
        $this->compra($token, $cartao, '2026-10-07', '100.00');
        CarbonImmutable::setTestNow('2026-10-10 09:00:00');
        app(ServicoFaturasCartao::class)->fecharTodas('2026-10-10');
        $fatura = (string) DB::table('faturas_cartao')->where('valor_total', '100.00')->value('uuid');

        $this->withToken($token)->postJson("/api/v1/contas/$cartao/faturas/$fatura/pagamento", ['data' => '2026-10-10'])
            ->assertOk()->assertJsonPath('fatura.situacao', 'paga');
        $this->withToken($token)->postJson("/api/v1/contas/$cartao/faturas/$fatura/pagamento", ['data' => '2026-10-10'])
            ->assertStatus(409);
        $this->assertDatabaseHas('transacoes', ['conta_id' => $this->id('contas', $conta), 'valor' => '-100.00', 'natureza' => 'pagamento_fatura']);
        $this->assertDatabaseHas('transacoes', ['conta_id' => $this->id('contas', $cartao), 'valor' => '100.00', 'natureza' => 'pagamento_fatura']);

        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-10-01&fim=2026-10-31')
            ->assertOk()->assertJsonPath('consolidado.saidas', '100.00')->assertJsonPath('consolidado.saldo_final', '900.00');
    }

    public function test_adiantamento_parcial_reduz_fatura_libera_limite_e_registra_saida_real(): void
    {
        $token = $this->autenticar('adiantamento@exemplo.test');
        $conta = $this->conta($token, 'Conta principal', '1000.00');
        $cartao = $this->cartao($token, $conta, 'Cartão', '1000.00');
        $this->compra($token, $cartao, '2026-10-07', '100.00');
        $fatura = (string) DB::table('faturas_cartao')->where('valor_total', '100.00')->value('uuid');

        $this->withToken($token)->postJson("/api/v1/contas/$cartao/faturas/$fatura/adiantamentos", [
            'data' => '2026-10-07', 'valor' => '30.00',
        ])->assertOk()->assertJsonPath('fatura.valor_total', '70.00')->assertJsonPath('fatura.situacao', 'aberta');
        $this->withToken($token)->postJson("/api/v1/contas/$cartao/faturas/$fatura/adiantamentos", [
            'data' => '2026-10-07', 'valor' => '20.00',
        ])->assertOk()->assertJsonPath('fatura.valor_total', '50.00');

        $faturaId = $this->id('faturas_cartao', $fatura);
        $this->assertSame(2, DB::table('transacoes')->where('conta_id', $this->id('contas', $conta))
            ->where('natureza', 'pagamento_fatura')->where('descricao_original', 'Adiantamento de fatura')->count());
        $this->assertSame(2, DB::table('transacoes')->where('conta_id', $this->id('contas', $cartao))
            ->where('fatura_cartao_id', $faturaId)->where('natureza', 'pagamento_fatura')->count());
        $this->assertDatabaseHas('auditoria_financeira', [
            'acao' => 'fatura_cartao_adiantada', 'entidade' => 'faturas_cartao', 'entidade_id' => $faturaId,
        ]);
        $this->assertSame('-50.00', number_format((float) DB::table('transacoes')
            ->where('conta_id', $this->id('contas', $conta))->where('natureza', 'pagamento_fatura')->sum('valor'), 2, '.', ''));
        $this->withToken($token)->getJson('/api/v1/contas')->assertOk()
            ->assertJsonFragment(['id' => $cartao, 'limite_utilizado' => '50.00', 'limite_disponivel' => '950.00']);
    }

    public function test_adiantamento_exige_valor_parcial_e_saldo_sem_gravacao_incompleta(): void
    {
        $token = $this->autenticar('adiantamento-validacao@exemplo.test');
        $conta = $this->conta($token, 'Conta baixa', '25.00');
        $cartao = $this->cartao($token, $conta, 'Cartão', '1000.00');
        $this->compra($token, $cartao, '2026-10-07', '100.00');
        $fatura = (string) DB::table('faturas_cartao')->where('valor_total', '100.00')->value('uuid');
        $quantidade = DB::table('transacoes')->count();

        $this->withToken($token)->postJson("/api/v1/contas/$cartao/faturas/$fatura/adiantamentos", [
            'data' => '2026-10-07', 'valor' => '100.00',
        ])->assertUnprocessable()->assertJsonPath('erros.valor.0', 'O adiantamento deve ser menor que o valor atual da fatura.');
        $this->withToken($token)->postJson("/api/v1/contas/$cartao/faturas/$fatura/adiantamentos", [
            'data' => '2026-10-07', 'valor' => '30.00',
        ])->assertStatus(409)->assertJson([
            'codigo' => 'saldo_insuficiente', 'saldo_disponivel' => '25.00',
            'valor_fatura' => '30.00', 'valor_faltante' => '5.00',
        ]);

        $this->assertSame($quantidade, DB::table('transacoes')->count());
        $this->assertDatabaseHas('faturas_cartao', ['uuid' => $fatura, 'valor_total' => '100.00', 'situacao' => 'aberta']);
    }

    public function test_pagamento_pode_vincular_debito_existente_sem_criar_nova_saida(): void
    {
        $token = $this->autenticar('conciliacao@exemplo.test');
        $conta = $this->conta($token, 'Conta', '1000.00');
        $cartao = $this->cartao($token, $conta, 'Cartão', '1000.00');
        $this->compra($token, $cartao, '2026-10-07', '75.00');
        CarbonImmutable::setTestNow('2026-10-10 09:00:00');
        app(ServicoFaturasCartao::class)->fecharTodas('2026-10-10');
        $fatura = (string) DB::table('faturas_cartao')->where('valor_total', '75.00')->value('uuid');
        $respostaDebito = $this->withToken($token)->postJson('/api/v1/transacoes', [
            'conta_id' => $conta, 'data' => '2026-10-10', 'valor' => '-75.00',
            'descricao' => 'Débito da operadora', 'natureza' => 'saida',
        ])->assertCreated();
        $debito = $respostaDebito->json('id');
        $codigo = $respostaDebito->json('codigo');
        $this->assertMatchesRegularExpression('/^[1-9][0-9]{8}$/', $codigo);
        $this->withToken($token)->getJson('/api/v1/transacoes?inicio=2026-10-01&fim=2026-10-31')
            ->assertOk()->assertJsonFragment(['id' => $debito, 'codigo' => $codigo]);
        $outroToken = $this->autenticar('codigo-alheio@exemplo.test');
        $contaAlheia = $this->conta($outroToken, 'Conta alheia');
        $codigoAlheio = $this->withToken($outroToken)->postJson('/api/v1/transacoes', [
            'conta_id' => $contaAlheia, 'data' => '2026-10-10', 'valor' => '-75.00',
            'descricao' => 'Débito alheio', 'natureza' => 'saida',
        ])->assertCreated()->json('codigo');
        $this->withToken($token)->postJson("/api/v1/contas/$cartao/faturas/$fatura/pagamento", [
            'data' => '2026-10-10', 'codigo_movimentacao' => "#$codigoAlheio",
        ])->assertUnprocessable()->assertJsonPath('erros.codigo_movimentacao.0', 'Código de movimentação inválido.');
        $quantidade = DB::table('transacoes')->count();

        $this->withToken($token)->postJson("/api/v1/contas/$cartao/faturas/$fatura/pagamento", [
            'data' => '2026-10-10', 'codigo_movimentacao' => "#$codigo",
        ])->assertOk()->assertJsonPath('fatura.situacao', 'paga');

        $this->assertSame($quantidade + 1, DB::table('transacoes')->count());
        $this->assertDatabaseHas('transacoes', ['uuid' => $debito, 'natureza' => 'pagamento_fatura', 'valor' => '-75.00']);
    }

    public function test_estorno_de_fatura_fechada_entra_como_credito_no_ciclo_seguinte(): void
    {
        $token = $this->autenticar('estorno@exemplo.test');
        $conta = $this->conta($token, 'Conta');
        $cartao = $this->cartao($token, $conta, 'Cartão', '1000.00');
        $compra = $this->compra($token, $cartao, '2026-10-07', '60.00');
        CarbonImmutable::setTestNow('2026-10-10 09:00:00');
        app(ServicoFaturasCartao::class)->fecharTodas('2026-10-10');

        $this->withToken($token)->postJson("/api/v1/transacoes/$compra/estornos", [
            'data' => '2026-10-11', 'motivo' => 'Compra cancelada',
        ])->assertCreated();

        $this->assertDatabaseHas('faturas_cartao', ['fechamento' => '2026-10-10', 'valor_total' => '60.00', 'situacao' => 'fechada']);
        $this->assertDatabaseHas('faturas_cartao', ['fechamento' => '2026-11-10', 'valor_total' => '0.00', 'credito_proximo' => '60.00']);
    }

    public function test_recorrencia_sem_limite_mantem_vencimento_e_registra_falha(): void
    {
        $token = $this->autenticar('recorrencia-limite@exemplo.test');
        $conta = $this->conta($token, 'Conta');
        $cartao = $this->cartao($token, $conta, 'Cartão', '100.00');
        $this->compra($token, $cartao, '2026-10-07', '90.00');
        $this->withToken($token)->postJson('/api/v1/pagamentos-recorrentes', [
            'conta_id' => $cartao, 'descricao' => 'Assinatura', 'valor' => '20.00',
            'periodicidade' => 'mensal', 'forma_pagamento' => 'cartao_credito',
            'cobranca_automatica' => true, 'primeiro_vencimento' => '2026-10-07', 'data_fim' => null,
        ])->assertCreated();

        $this->assertSame(0, app(ServicoPagamentosRecorrentes::class)->processarAutomaticas('2026-10-07'));
        $this->assertDatabaseHas('pagamentos_recorrentes', [
            'proximo_vencimento' => '2026-10-07', 'falha_automatica' => 'limite_insuficiente',
        ]);
        $this->assertDatabaseCount('transacoes', 1);
    }

    public function test_troca_de_conta_altera_apenas_fatura_aberta_e_isolamento_e_mantido(): void
    {
        $token = $this->autenticar('vinculo@exemplo.test');
        $outroToken = $this->autenticar('outro@exemplo.test');
        $contaA = $this->conta($token, 'Conta A');
        $contaB = $this->conta($token, 'Conta B');
        $contaOutro = $this->conta($outroToken, 'Conta alheia');
        $cartao = $this->cartao($token, $contaA, 'Cartão', '1000.00');
        $this->compra($token, $cartao, '2026-10-07', '25.00');
        CarbonImmutable::setTestNow('2026-10-10 09:00:00');
        app(ServicoFaturasCartao::class)->fecharTodas('2026-10-10');

        $this->withToken($token)->patchJson("/api/v1/contas/$cartao", ['conta_pagamento_id' => $contaOutro])->assertUnprocessable();
        $this->withToken($token)->patchJson("/api/v1/contas/$cartao", ['conta_pagamento_id' => $contaB])->assertOk();
        $this->assertDatabaseHas('faturas_cartao', ['fechamento' => '2026-10-10', 'conta_pagamento_id' => $this->id('contas', $contaA)]);
        $this->assertDatabaseHas('faturas_cartao', ['fechamento' => '2026-11-10', 'conta_pagamento_id' => $this->id('contas', $contaB)]);
    }

    private function autenticar(string $email): string
    {
        $usuario = Usuario::query()->create([
            'nome' => 'Pessoa Teste', 'email' => $email,
            'senha' => Hash::driver('argon2id')->make('SenhaForte12345!'),
        ]);

        return app(ServicoToken::class)->emitir($usuario);
    }

    private function conta(string $token, string $nome, string $saldo = '1000.00', ?string $instituicao = null): string
    {
        return $this->withToken($token)->postJson('/api/v1/contas', [
            'nome' => $nome, 'tipo' => 'corrente', 'moeda' => 'BRL', 'saldo_inicial' => $saldo,
            'instituicao' => $instituicao,
        ])->assertCreated()->json('id');
    }

    private function cartao(string $token, string $conta, string $nome, string $limite, int $fechamento = 10, int $vencimento = 17): string
    {
        return $this->withToken($token)->postJson('/api/v1/contas', [
            'nome' => $nome, 'tipo' => 'cartao_credito', 'conta_pagamento_id' => $conta,
            'limite_credito' => $limite, 'dia_fechamento' => $fechamento, 'dia_vencimento' => $vencimento,
        ])->assertCreated()->json('id');
    }

    private function compra(string $token, string $cartao, string $data, string $valor): string
    {
        return $this->withToken($token)->postJson('/api/v1/transacoes', $this->dadosCompra($cartao, $data, $valor))
            ->assertCreated()->json('id');
    }

    /** @return array<string, string> */
    private function dadosCompra(string $cartao, string $data, string $valor): array
    {
        return ['conta_id' => $cartao, 'data' => $data, 'valor' => "-$valor", 'descricao' => 'Compra teste', 'natureza' => 'compra_cartao'];
    }

    private function id(string $tabela, string $uuid): int
    {
        return (int) DB::table($tabela)->where('uuid', $uuid)->value('id');
    }
}
