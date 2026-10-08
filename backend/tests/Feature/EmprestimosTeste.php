<?php

namespace Tests\Feature;

use App\Modulos\Usuarios\Modelos\Usuario;
use App\Modulos\Usuarios\Servicos\ServicoToken;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class EmprestimosTeste extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('autenticacao.segredo', base64_encode(random_bytes(32)));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_cadastro_e_pagamento_separam_divida_de_receita_e_principal_de_despesa(): void
    {
        [$token, $conta] = $this->usuarioComConta('emprestimo@exemplo.test');
        $emprestimo = $this->withToken($token)->postJson('/api/v1/emprestimos', $this->dados($conta))
            ->assertCreated()->assertJsonPath('parcelas.0.valor_total', '434.60')
            ->assertJsonPath('saldo_devedor', '1200.00')
            ->assertJsonPath('saldo_financiado', '1230.00')->json();

        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-09-01&fim=2026-09-30&moeda=BRL')
            ->assertOk()->assertJsonPath('consolidado.saldo_final', '2200.00')
            ->assertJsonPath('consolidado.receitas', '0.00')->assertJsonPath('consolidado.entradas', '0.00');

        $this->withToken($token)->postJson("/api/v1/emprestimos/{$emprestimo['id']}/parcelas/{$emprestimo['parcelas'][0]['id']}/pagamentos", [
            'conta_pagamento_id' => $conta, 'data_pagamento' => '2026-09-18',
        ])->assertOk()->assertJsonPath('parcelas.0.situacao', 'paga')
            ->assertJsonPath('saldo_devedor', '800.00')->assertJsonPath('saldo_financiado', '820.00');

        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-09-01&fim=2026-09-30&moeda=BRL')
            ->assertOk()->assertJsonPath('consolidado.saldo_final', '1765.40')
            ->assertJsonPath('consolidado.despesas', '34.60')->assertJsonPath('consolidado.saidas', '34.60');
        $this->assertDatabaseHas('transacoes', ['natureza' => 'pagamento_emprestimo', 'valor' => -400]);
        $this->assertDatabaseHas('categorias', ['nome' => 'Juros e taxas', 'tipo' => 'despesa']);
    }

    public function test_quitacao_antecipada_e_isolamento_entre_usuarios(): void
    {
        [$ana, $contaAna] = $this->usuarioComConta('ana-emprestimo@exemplo.test');
        $id = $this->withToken($ana)->postJson('/api/v1/emprestimos', $this->dados($contaAna))->assertCreated()->json('id');
        [$bia, $contaBia] = $this->usuarioComConta('bia-emprestimo@exemplo.test');

        $this->withToken($bia)->getJson("/api/v1/emprestimos/$id")->assertNotFound();
        $this->withToken($bia)->deleteJson("/api/v1/emprestimos/$id", ['confirmar' => true])->assertNotFound();
        $this->withToken($bia)->postJson("/api/v1/emprestimos/$id/quitacao", [
            'conta_pagamento_id' => $contaBia, 'data_pagamento' => '2026-09-18', 'juros' => '0.00', 'taxas' => '0.00',
        ])->assertNotFound();
        $this->withToken($ana)->postJson("/api/v1/emprestimos/$id/quitacao", [
            'conta_pagamento_id' => $contaAna, 'data_pagamento' => '2026-09-18', 'juros' => '5.00', 'taxas' => '2.00',
        ])->assertOk()->assertJsonPath('situacao', 'quitado')->assertJsonPath('saldo_devedor', '0.00');
        $this->assertDatabaseCount('parcelas_emprestimo', 3);
        $this->assertDatabaseMissing('parcelas_emprestimo', [
            'emprestimo_id' => $this->idInterno('emprestimos', $id), 'situacao' => 'pendente',
        ]);
    }

    public function test_transacao_existente_e_associada_sem_criar_divida_duplicada(): void
    {
        [$token, $conta] = $this->usuarioComConta('associacao-emprestimo@exemplo.test');
        $emprestimo = $this->withToken($token)->postJson('/api/v1/emprestimos', [
            ...$this->dados($conta), 'afetar_saldo_recebimento' => false,
        ])->assertCreated()->json();
        $transacao = $this->withToken($token)->postJson('/api/v1/transacoes', [
            'conta_id' => $conta, 'data' => '2026-09-18', 'valor' => '-434.60',
            'descricao' => 'EMPRESTIMO BANCO EXEMPLO', 'natureza' => 'saida',
        ])->assertCreated()->json();

        $this->withToken($token)->postJson(
            "/api/v1/emprestimos/{$emprestimo['id']}/parcelas/{$emprestimo['parcelas'][0]['id']}/transacao",
            ['transacao_id' => $transacao['id']],
        )->assertOk()->assertJsonPath('parcelas.0.situacao', 'paga')
            ->assertJsonPath('parcelas_pagas', 1)->assertJsonPath('parcelas_restantes', 2)
            ->assertJsonPath('saldo_financiado', '820.00');

        $this->assertDatabaseCount('emprestimos', 1);
        $this->assertDatabaseCount('transacoes', 1);
        $this->assertDatabaseHas('parcelas_emprestimo', [
            'uuid' => $emprestimo['parcelas'][0]['id'],
            'transacao_pagamento_id' => $this->idInterno('transacoes', $transacao['id']),
        ]);
        $this->withToken($token)->postJson(
            "/api/v1/emprestimos/{$emprestimo['id']}/parcelas/{$emprestimo['parcelas'][1]['id']}/transacao",
            ['transacao_id' => $transacao['id']],
        )->assertStatus(409);
        $this->withToken($token)->deleteJson("/api/v1/emprestimos/{$emprestimo['id']}", ['confirmar' => true])->assertOk();
        $this->assertDatabaseHas('transacoes', ['uuid' => $transacao['id']]);
    }

    public function test_pagamento_de_parcela_pode_ser_desfeito_sem_manter_movimentacoes_geradas(): void
    {
        [$token, $conta] = $this->usuarioComConta('desfazer-pagamento@exemplo.test');
        $emprestimo = $this->withToken($token)->postJson('/api/v1/emprestimos', $this->dados($conta))
            ->assertCreated()->json();
        $rota = "/api/v1/emprestimos/{$emprestimo['id']}/parcelas/{$emprestimo['parcelas'][0]['id']}/pagamentos";

        $this->withToken($token)->postJson($rota, [
            'conta_pagamento_id' => $conta, 'data_pagamento' => '2026-09-18',
        ])->assertOk()->assertJsonPath('parcelas.0.situacao', 'paga');
        $this->assertDatabaseCount('transacoes', 3);
        foreach ([1, 2] as $indice) {
            $resposta = $this->withToken($token)->postJson(
                "/api/v1/emprestimos/{$emprestimo['id']}/parcelas/{$emprestimo['parcelas'][$indice]['id']}/pagamentos",
                ['data_pagamento' => '2026-09-18', 'afetar_saldo' => false],
            )->assertOk();
        }
        $resposta->assertJsonPath('situacao', 'quitado');

        $this->withToken($token)->deleteJson($rota)
            ->assertOk()->assertJsonPath('parcelas.0.situacao', 'pendente')
            ->assertJsonPath('parcelas.0.data_pagamento', null)
            ->assertJsonPath('parcelas_pagas', 2)
            ->assertJsonPath('situacao', 'ativo');

        $this->assertDatabaseCount('transacoes', 1);
        $this->assertDatabaseHas('parcelas_emprestimo', [
            'uuid' => $emprestimo['parcelas'][0]['id'],
            'situacao' => 'pendente',
            'transacao_principal_id' => null,
            'transacao_encargos_id' => null,
        ]);
    }

    public function test_desfazer_pagamento_preserva_transacao_associada_e_respeita_o_usuario(): void
    {
        [$dono, $contaDono] = $this->usuarioComConta('dono-desfazer@exemplo.test');
        $emprestimo = $this->withToken($dono)->postJson('/api/v1/emprestimos', [
            ...$this->dados($contaDono), 'afetar_saldo_recebimento' => false,
        ])->assertCreated()->json();
        $transacao = $this->withToken($dono)->postJson('/api/v1/transacoes', [
            'conta_id' => $contaDono, 'data' => '2026-09-18', 'valor' => '-434.60',
            'descricao' => 'Parcela já debitada', 'natureza' => 'saida',
        ])->assertCreated()->json();
        $rota = "/api/v1/emprestimos/{$emprestimo['id']}/parcelas/{$emprestimo['parcelas'][0]['id']}";

        $this->withToken($dono)->postJson("$rota/transacao", ['transacao_id' => $transacao['id']])
            ->assertOk()->assertJsonPath('parcelas.0.situacao', 'paga');
        [$outro] = $this->usuarioComConta('outro-desfazer@exemplo.test');
        $this->withToken($outro)->deleteJson("$rota/pagamentos")->assertNotFound();

        $this->withToken($dono)->deleteJson("$rota/pagamentos")
            ->assertOk()->assertJsonPath('parcelas.0.situacao', 'pendente');
        $this->assertDatabaseHas('transacoes', ['uuid' => $transacao['id']]);
        $this->assertDatabaseHas('parcelas_emprestimo', [
            'uuid' => $emprestimo['parcelas'][0]['id'], 'transacao_pagamento_id' => null,
        ]);
        $this->withToken($dono)->deleteJson("$rota/pagamentos")->assertStatus(409);
    }

    public function test_contrato_preserva_valores_bancarios_e_expoe_indicadores_e_simulacao(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        [$token, $conta] = $this->usuarioComConta('contrato-emprestimo@exemplo.test');
        $dados = [
            ...$this->dados($conta),
            'tipo_divida' => 'emprestimo_pessoal', 'valor_principal' => '401.82',
            'valor_recebido' => '401.82', 'valor_financiado' => '412.37',
            'iof' => '10.55', 'taxas_totais' => '10.55', 'taxa_juros_mensal' => '4.96',
            'taxa_juros_anual' => '78.61', 'cet_mensal' => '5.33', 'cet_anual' => '86.45',
            'quantidade_parcelas' => 12, 'valor_parcela_contratada' => '49.98',
            'total_contratado' => '599.76', 'data_contratacao' => '2026-05-14',
            'data_recebimento' => '2026-05-15', 'data_base_calculo' => '2026-05-14',
            'primeiro_vencimento' => '2026-07-26', 'afetar_saldo_recebimento' => false,
        ];
        $emprestimo = $this->withToken($token)->postJson('/api/v1/emprestimos', $dados)
            ->assertCreated()->assertJsonPath('valor_recebido', '401.82')
            ->assertJsonPath('valor_financiado', '412.37')->assertJsonPath('iof', '10.55')
            ->assertJsonPath('total_previsto', '599.76')
            ->assertJsonPath('custo_total_credito', '197.94')
            ->assertJsonPath('valor_parcela_contratada', '49.98')->json();

        $this->withToken($token)->getJson("/api/v1/emprestimos/{$emprestimo['id']}/cronograma")
            ->assertOk()->assertJsonCount(12, 'parcelas')->assertJsonPath('parcelas.11.saldo_final', '0.00');
        $this->withToken($token)->postJson("/api/v1/emprestimos/{$emprestimo['id']}/simulacao-quitacao", [
            'valor_quitacao' => '325.00',
        ])->assertOk()->assertJsonPath('valor_quitacao', '325.00')
            ->assertJsonPath('origem_valor_quitacao', 'informado_pelo_banco');
        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-09-01&fim=2026-09-30&moeda=BRL')
            ->assertOk()->assertJsonPath('dividas.quantidade_contratos_ativos', 1)
            ->assertJsonPath('dividas.saldo_devedor_total', '412.37');
    }

    public function test_parcelas_anteriores_ao_cadastro_nao_se_aplicam_ao_acompanhamento(): void
    {
        CarbonImmutable::setTestNow('2026-09-23 12:00:00');
        try {
            [$token, $conta] = $this->usuarioComConta('parcelas-anteriores@exemplo.test');
            $dados = [
                ...$this->dados($conta),
                'quantidade_parcelas' => 4,
                'data_recebimento' => '2026-06-23',
                'primeiro_vencimento' => '2026-07-23',
                'afetar_saldo_recebimento' => false,
            ];

            $emprestimo = $this->withToken($token)->postJson('/api/v1/emprestimos', $dados)
                ->assertCreated()
                ->assertJsonPath('situacao_exibicao', 'ativo')
                ->assertJsonPath('parcelas.0.situacao_exibicao', 'nao_se_aplica')
                ->assertJsonPath('parcelas.1.situacao_exibicao', 'nao_se_aplica')
                ->assertJsonPath('parcelas.2.situacao_exibicao', 'pendente')
                ->assertJsonPath('parcelas_nao_aplicaveis', 2)
                ->assertJsonPath('parcelas_pendentes', 2)
                ->assertJsonPath('proxima_parcela.numero', 3)
                ->json();

            $this->withToken($token)->postJson(
                "/api/v1/emprestimos/{$emprestimo['id']}/parcelas/{$emprestimo['parcelas'][0]['id']}/pagamentos",
                ['data_pagamento' => '2026-09-23', 'afetar_saldo' => false],
            )->assertStatus(409);
            $this->withToken($token)->postJson("/api/v1/emprestimos/{$emprestimo['id']}/simulacao-quitacao")
                ->assertOk()->assertJsonPath('parcelas_restantes', 2);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_emprestimo_historico_pode_ser_editado_pago_e_excluido_sem_afetar_saldo(): void
    {
        [$token, $conta] = $this->usuarioComConta('historico-emprestimo@exemplo.test');
        $dados = $this->dados($conta) + ['afetar_saldo_recebimento' => false];
        $emprestimo = $this->withToken($token)->postJson('/api/v1/emprestimos', $dados)
            ->assertCreated()
            ->assertJsonPath('afeta_saldo_recebimento', false)
            ->json();
        $this->assertDatabaseCount('transacoes', 0);

        $editados = [...$dados, 'credor' => 'Banco Corrigido', 'valor_principal' => '1300.00'];
        $emprestimo = $this->withToken($token)->patchJson("/api/v1/emprestimos/{$emprestimo['id']}", $editados)
            ->assertOk()
            ->assertJsonPath('credor', 'Banco Corrigido')
            ->assertJsonPath('saldo_devedor', '1300.00')
            ->assertJsonCount(3, 'parcelas')
            ->json();

        $this->withToken($token)->postJson("/api/v1/emprestimos/{$emprestimo['id']}/parcelas/{$emprestimo['parcelas'][0]['id']}/pagamentos", [
            'data_pagamento' => '2026-09-18', 'afetar_saldo' => false,
        ])->assertOk()->assertJsonPath('parcelas.0.situacao', 'paga');
        $this->assertDatabaseCount('transacoes', 0);
        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-09-01&fim=2026-09-30&moeda=BRL')
            ->assertOk()->assertJsonPath('consolidado.saldo_final', '1000.00');

        $this->withToken($token)->deleteJson("/api/v1/emprestimos/{$emprestimo['id']}", ['confirmar' => true])
            ->assertOk()->assertJsonPath('excluido', true);
        $this->assertDatabaseCount('emprestimos', 0);
        $this->assertDatabaseCount('parcelas_emprestimo', 0);
        $this->assertDatabaseCount('transacoes', 0);
    }

    public function test_edicao_preserva_cronograma_apos_pagamento_e_bloqueia_recalculo(): void
    {
        [$token, $conta] = $this->usuarioComConta('edicao-emprestimo@exemplo.test');
        $emprestimo = $this->withToken($token)->postJson('/api/v1/emprestimos', $this->dados($conta))
            ->assertCreated()->json();
        $this->assertDatabaseHas('transacoes', [
            'uuid' => $emprestimo['transacao_recebimento_id'], 'valor' => 1200,
        ]);

        $editados = [...$this->dados($conta), 'valor_principal' => '1300.00'];
        $emprestimo = $this->withToken($token)->patchJson("/api/v1/emprestimos/{$emprestimo['id']}", $editados)
            ->assertOk()->assertJsonPath('saldo_devedor', '1300.00')->json();
        $this->assertDatabaseCount('transacoes', 1);
        $this->assertDatabaseHas('transacoes', [
            'uuid' => $emprestimo['transacao_recebimento_id'], 'valor' => 1300,
        ]);

        $this->withToken($token)->postJson("/api/v1/emprestimos/{$emprestimo['id']}/parcelas/{$emprestimo['parcelas'][0]['id']}/pagamentos", [
            'data_pagamento' => '2026-09-18', 'afetar_saldo' => false,
        ])->assertOk();
        $emprestimoId = $this->idInterno('emprestimos', $emprestimo['id']);
        $parcelasAntes = DB::table('parcelas_emprestimo')->where('emprestimo_id', $emprestimoId)
            ->get(['id', 'valor_total'])->map(fn (object $parcela) => (array) $parcela)->all();
        $this->withToken($token)->patchJson("/api/v1/emprestimos/{$emprestimo['id']}", [
            ...$editados, 'credor' => 'Banco Renomeado', 'descricao' => 'Contrato revisado',
            'cet_mensal' => '3.00', 'valor_parcela_contratada' => '450.00',
        ])->assertOk()->assertJsonPath('credor', 'Banco Renomeado')
            ->assertJsonPath('descricao', 'Contrato revisado')
            ->assertJsonPath('valor_parcela_contratada', '450.00');
        $parcelasDepois = DB::table('parcelas_emprestimo')->where('emprestimo_id', $emprestimoId)
            ->get(['id', 'valor_total'])->map(fn (object $parcela) => (array) $parcela)->all();
        $this->assertSame($parcelasAntes, $parcelasDepois);

        $this->withToken($token)->patchJson("/api/v1/emprestimos/{$emprestimo['id']}", [
            ...$editados, 'valor_principal' => '1400.00',
        ])
            ->assertStatus(409);

        $this->withToken($token)->deleteJson("/api/v1/emprestimos/{$emprestimo['id']}", ['confirmar' => false])
            ->assertUnprocessable();
        $this->assertDatabaseHas('emprestimos', ['uuid' => $emprestimo['id']]);
        $this->withToken($token)->deleteJson("/api/v1/emprestimos/{$emprestimo['id']}", ['confirmar' => true])
            ->assertOk();
        $this->assertDatabaseCount('transacoes', 0);
    }

    public function test_validacao_rejeita_campos_desconhecidos_e_total_manual_invalido(): void
    {
        [$token, $conta] = $this->usuarioComConta('validacao@exemplo.test');
        $dados = $this->dados($conta) + ['campo_indevido' => '<script>alert(1)</script>'];
        $this->withToken($token)->postJson('/api/v1/emprestimos', $dados)->assertUnprocessable();
        $manual = $this->dados($conta);
        $manual['sistema_amortizacao'] = 'manual';
        $manual['valor_total_manual'] = '1000.00';
        $this->withToken($token)->postJson('/api/v1/emprestimos', $manual)->assertUnprocessable();
        $this->assertDatabaseCount('emprestimos', 0);
    }

    public function test_exportacao_e_exclusao_incluem_emprestimos_e_parcelas(): void
    {
        [$token, $conta] = $this->usuarioComConta('privacidade-emprestimo@exemplo.test');
        $this->withToken($token)->postJson('/api/v1/emprestimos', $this->dados($conta))->assertCreated();
        $exportacao = json_decode($this->withToken($token)->get('/api/v1/usuarios/eu/exportacao')
            ->assertOk()->streamedContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $exportacao['emprestimos']);
        $this->assertCount(3, $exportacao['parcelas_emprestimo']);

        $this->withToken($token)->deleteJson('/api/v1/usuarios/eu', [
            'senha' => 'SenhaForte12345!', 'confirmar' => true,
        ])->assertOk();
        $this->assertDatabaseCount('emprestimos', 0);
        $this->assertDatabaseCount('parcelas_emprestimo', 0);
    }

    private function usuarioComConta(string $email): array
    {
        $usuario = Usuario::query()->create(['nome' => 'Pessoa', 'email' => $email, 'senha' => Hash::driver('argon2id')->make('SenhaForte12345!')]);
        $token = app(ServicoToken::class)->emitir($usuario);
        $conta = $this->withToken($token)->postJson('/api/v1/contas', [
            'nome' => 'Conta principal', 'tipo' => 'corrente', 'moeda' => 'BRL',
            'saldo_inicial' => '1000.00',
        ])->assertCreated()->json('id');

        return [$token, $conta];
    }

    private function dados(string $conta): array
    {
        return [
            'conta_recebimento_id' => $conta, 'credor' => 'Banco Exemplo', 'descricao' => 'Crédito pessoal',
            'valor_principal' => '1200.00', 'taxas_totais' => '30.00', 'taxa_juros_mensal' => '2.00',
            'sistema_amortizacao' => 'sac', 'quantidade_parcelas' => 3,
            'data_recebimento' => '2026-09-10', 'primeiro_vencimento' => '2026-10-10',
            'valor_total_manual' => null,
        ];
    }

    private function idInterno(string $tabela, string $uuid): int
    {
        return (int) DB::table($tabela)->where('uuid', $uuid)->value('id');
    }
}
