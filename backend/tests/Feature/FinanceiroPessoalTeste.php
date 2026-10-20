<?php

namespace Tests\Feature;

use App\Modulos\Financeiro\Cartoes\ServicoFaturasCartao;
use App\Modulos\Usuarios\Modelos\Usuario;
use App\Modulos\Usuarios\Servicos\ServicoToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class FinanceiroPessoalTeste extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('autenticacao.segredo', base64_encode(random_bytes(32)));
    }

    private function autenticar(string $email): string
    {
        $usuario = Usuario::query()->create([
            'nome' => 'Pessoa Teste', 'email' => $email,
            'senha' => Hash::driver('argon2id')->make('SenhaForte12345!'),
        ]);

        return app(ServicoToken::class)->emitir($usuario);
    }

    private function conta(string $token, string $nome, string $tipo = 'corrente'): string
    {
        if ($tipo === 'cartao_credito') {
            $pagadora = $this->conta($token, "$nome - conta vinculada");

            return $this->withToken($token)->postJson('/api/v1/contas', [
                'nome' => $nome, 'tipo' => $tipo, 'conta_pagamento_id' => $pagadora,
                'limite_credito' => '5000.00', 'dia_fechamento' => 10, 'dia_vencimento' => 17,
            ])->assertCreated()->json('id');
        }

        return $this->withToken($token)->postJson('/api/v1/contas', [
            'nome' => $nome, 'tipo' => $tipo, 'moeda' => 'BRL',
            'saldo_inicial' => '1000.00',
        ])->assertCreated()->json('id');
    }

    public function test_criacao_da_conta_nao_exige_nem_expoe_data_base(): void
    {
        $token = $this->autenticar('data-automatica@exemplo.test');

        $this->withToken($token)->postJson('/api/v1/contas', [
            'nome' => 'Conta automática', 'tipo' => 'corrente', 'moeda' => 'BRL',
            'saldo_inicial' => '100.00',
        ])->assertCreated()->assertJsonMissingPath('data_saldo');
    }

    public function test_identificador_publico_usa_uuid_e_nao_aceita_id_sequencial(): void
    {
        $token = $this->autenticar('uuid-publico@exemplo.test');
        $resposta = $this->withToken($token)->postJson('/api/v1/contas', [
            'nome' => 'Conta UUID', 'tipo' => 'corrente', 'moeda' => 'BRL', 'saldo_inicial' => '10.00',
        ])->assertCreated()->assertJsonMissingPath('uuid');

        $uuid = $resposta->json('id');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
        $this->withToken($token)->patchJson('/api/v1/contas/1', [
            'nome' => 'Tentativa por ID', 'instituicao' => null, 'situacao' => 'ativa',
        ])->assertNotFound();
        $this->assertDatabaseHas('contas', ['uuid' => $uuid, 'nome' => 'Conta UUID']);
    }

    public function test_resumo_por_moeda_e_historico_recente_preservam_contas_separadas(): void
    {
        $token = $this->autenticar('moedas@exemplo.test');
        $brl = $this->conta($token, 'Conta em reais');
        $this->withToken($token)->postJson('/api/v1/contas', [
            'nome' => 'Conta em dolares', 'tipo' => 'corrente', 'moeda' => 'USD',
            'saldo_inicial' => '50.00',
        ])->assertCreated();
        foreach (['Primeira despesa', 'Segunda despesa'] as $descricao) {
            $this->withToken($token)->postJson('/api/v1/transacoes', [
                'conta_id' => $brl, 'data' => '2026-09-05', 'valor' => '-10.00',
                'descricao' => $descricao, 'natureza' => 'saida',
            ])->assertCreated();
        }
        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-09-01&fim=2026-09-30')
            ->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-09-01&fim=2026-09-30&moeda=BRL')
            ->assertOk()->assertJsonCount(1, 'contas')
            ->assertJsonPath('consolidado.saldo_final', '980.00')
            ->assertJsonPath('consolidado.pendentes', 2);
        $this->withToken($token)->getJson('/api/v1/transacoes?moeda=BRL&ordem=desc&limite=1')
            ->assertOk()->assertJsonPath('dados.0.descricao_original', 'Segunda despesa')
            ->assertJsonStructure(['proximo_cursor']);
    }

    public function test_resumo_distribui_despesas_classificadas_por_categoria(): void
    {
        $token = $this->autenticar('categorias-resumo@exemplo.test');
        $conta = $this->conta($token, 'Conta principal');
        $casa = $this->withToken($token)->postJson('/api/v1/categorias', [
            'nome' => 'Casa', 'tipo' => 'despesa', 'limite_gastos' => '60.00',
        ])->assertCreated()->json('id');
        $transporte = $this->withToken($token)->postJson('/api/v1/categorias', [
            'nome' => 'Transporte', 'tipo' => 'despesa',
        ])->assertCreated()->json('id');

        foreach ([[$casa, '-75.00'], [$transporte, '-25.00']] as [$categoria, $valor]) {
            $this->withToken($token)->postJson('/api/v1/transacoes', [
                'conta_id' => $conta, 'data' => '2026-09-10', 'valor' => $valor,
                'descricao' => 'Despesa categorizada', 'natureza' => 'saida', 'categoria_id' => $categoria,
            ])->assertCreated();
        }
        $this->withToken($token)->postJson('/api/v1/transacoes', [
            'conta_id' => $conta, 'data' => '2026-09-10', 'valor' => '-40.00',
            'descricao' => 'Despesa pendente', 'natureza' => 'saida',
        ])->assertCreated();

        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-09-01&fim=2026-09-30&moeda=BRL')
            ->assertOk()
            ->assertJsonPath('consolidado.saidas', '140.00')
            ->assertJsonPath('consolidado.despesas', '100.00')
            ->assertJsonCount(2, 'despesas_por_categoria')
            ->assertJsonPath('despesas_por_categoria.0.nome', 'Casa')
            ->assertJsonPath('despesas_por_categoria.0.total', '75.00')
            ->assertJsonPath('despesas_por_categoria.0.percentual', '75.00')
            ->assertJsonPath('despesas_por_categoria.0.limite_gastos', '60.00')
            ->assertJsonPath('despesas_por_categoria.0.percentual_limite', '125.00')
            ->assertJsonPath('despesas_por_categoria.0.limite_ultrapassado', true)
            ->assertJsonPath('despesas_por_categoria.1.nome', 'Transporte')
            ->assertJsonPath('despesas_por_categoria.1.limite_gastos', null)
            ->assertJsonPath('despesas_por_categoria.1.percentual_limite', null)
            ->assertJsonPath('despesas_por_categoria.1.limite_ultrapassado', false)
            ->assertJsonPath('despesas_por_categoria.1.percentual', '25.00');
    }

    public function test_limite_de_gastos_da_categoria_pode_ser_criado_e_atualizado_com_seguranca(): void
    {
        $ana = $this->autenticar('limite-ana@exemplo.test');
        $categoria = $this->withToken($ana)->postJson('/api/v1/categorias', [
            'nome' => 'Alimentação', 'tipo' => 'despesa', 'limite_gastos' => '500.00',
        ])->assertCreated()->assertJsonPath('limite_gastos', '500.00')->json('id');

        $this->withToken($ana)->patchJson("/api/v1/categorias/$categoria", [
            'limite_gastos' => '650.50',
        ])->assertOk()->assertJsonPath('limite_gastos', '650.50');
        $this->assertDatabaseHas('auditoria_financeira', [
            'usuario_id' => 1, 'acao' => 'limite_categoria_atualizado',
            'entidade' => 'categorias',
            'entidade_id' => (int) DB::table('categorias')->where('uuid', $categoria)->value('id'),
        ]);

        $bia = $this->autenticar('limite-bia@exemplo.test');
        $this->withToken($bia)->patchJson("/api/v1/categorias/$categoria", [
            'limite_gastos' => '10.00',
        ])->assertNotFound();
        $this->withToken($ana)->patchJson("/api/v1/categorias/$categoria", [
            'limite_gastos' => '00.00',
        ])->assertUnprocessable();
        $this->withToken($ana)->patchJson("/api/v1/categorias/$categoria", [])
            ->assertUnprocessable();
        $this->withToken($ana)->postJson('/api/v1/categorias', [
            'nome' => 'Salário', 'tipo' => 'receita', 'limite_gastos' => '100.00',
        ])->assertUnprocessable();
    }

    public function test_categoria_pode_ser_editada_sem_reinterpretar_movimentacoes_existentes(): void
    {
        $token = $this->autenticar('editar-categoria@exemplo.test');
        $conta = $this->conta($token, 'Conta principal');
        $categoria = $this->withToken($token)->postJson('/api/v1/categorias', [
            'nome' => 'Mercado', 'tipo' => 'despesa', 'limite_gastos' => '400.00',
        ])->assertCreated()->json('id');

        $this->withToken($token)->patchJson("/api/v1/categorias/$categoria", [
            'nome' => 'Alimentação', 'tipo' => 'despesa', 'limite_gastos' => '550.00',
        ])->assertOk()
            ->assertJsonPath('nome', 'Alimentação')
            ->assertJsonPath('tipo', 'despesa')
            ->assertJsonPath('limite_gastos', '550.00');
        $this->assertDatabaseHas('auditoria_financeira', [
            'usuario_id' => 1, 'acao' => 'categoria_atualizada',
            'entidade' => 'categorias',
            'entidade_id' => (int) DB::table('categorias')->where('uuid', $categoria)->value('id'),
        ]);

        $this->withToken($token)->postJson('/api/v1/transacoes', [
            'conta_id' => $conta, 'data' => '2026-09-10', 'valor' => '-25.00',
            'descricao' => 'Compra', 'natureza' => 'saida', 'categoria_id' => $categoria,
        ])->assertCreated();
        $this->withToken($token)->patchJson("/api/v1/categorias/$categoria", [
            'tipo' => 'receita', 'limite_gastos' => null,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('categorias', [
            'uuid' => $categoria, 'nome' => 'Alimentação', 'tipo' => 'despesa', 'limite_gastos' => 550.00,
        ]);

        $semUso = $this->withToken($token)->postJson('/api/v1/categorias', [
            'nome' => 'Outros', 'tipo' => 'despesa',
        ])->assertCreated()->json('id');
        $this->withToken($token)->patchJson("/api/v1/categorias/$semUso", [
            'nome' => 'Alimentação',
        ])->assertUnprocessable();
        $this->withToken($token)->patchJson("/api/v1/categorias/$semUso", [
            'nome' => 'Renda extra', 'tipo' => 'receita', 'limite_gastos' => null,
        ])->assertOk()->assertJsonPath('tipo', 'receita');
    }

    public function test_historico_combina_filtros_e_preserva_a_paginacao(): void
    {
        $token = $this->autenticar('filtros@exemplo.test');
        $conta = $this->conta($token, 'Conta filtrada');
        $categoria = $this->withToken($token)->postJson('/api/v1/categorias', [
            'nome' => 'Casa', 'tipo' => 'despesa',
        ])->assertCreated()->json('id');

        foreach (['Compra de pilhas', 'Compra de velas'] as $descricao) {
            $this->withToken($token)->postJson('/api/v1/transacoes', [
                'conta_id' => $conta, 'data' => '2026-09-20', 'valor' => '-7.59',
                'descricao' => $descricao, 'natureza' => 'saida', 'categoria_id' => $categoria,
            ])->assertCreated();
        }
        $this->withToken($token)->postJson('/api/v1/transacoes', [
            'conta_id' => $conta, 'data' => '2026-09-20', 'valor' => '20.00',
            'descricao' => 'Reembolso', 'natureza' => 'entrada',
        ])->assertCreated();

        $consulta = "/api/v1/transacoes?busca=Compra&categoria_id=$categoria&natureza=saida&origem=manual&ordem=desc&limite=1";
        $primeira = $this->withToken($token)->getJson($consulta)
            ->assertOk()->assertJsonCount(1, 'dados')
            ->assertJsonPath('dados.0.descricao_original', 'Compra de velas');
        $cursor = $primeira->json('proximo_cursor');
        $this->assertNotNull($cursor);
        $this->withToken($token)->getJson("$consulta&apos=$cursor")
            ->assertOk()->assertJsonCount(1, 'dados')
            ->assertJsonPath('dados.0.descricao_original', 'Compra de pilhas');

        $this->withToken($token)->getJson('/api/v1/transacoes?sem_categoria=1&natureza=entrada')
            ->assertOk()->assertJsonCount(1, 'dados')
            ->assertJsonPath('dados.0.descricao_original', 'Reembolso');
        $this->withToken($token)->getJson("/api/v1/transacoes?categoria_id=$categoria&sem_categoria=1")
            ->assertUnprocessable();
    }

    public function test_historico_separa_lancamentos_em_debito_e_cartao_de_credito(): void
    {
        $token = $this->autenticar('historico-forma-pagamento@exemplo.test');
        $conta = $this->conta($token, 'Conta corrente');
        $cartao = $this->conta($token, 'Cartão principal', 'cartao_credito');

        $this->withToken($token)->postJson('/api/v1/transacoes', [
            'conta_id' => $conta, 'data' => '2026-09-20', 'valor' => '-80.00',
            'descricao' => 'Pagamento no débito', 'natureza' => 'saida',
        ])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/transacoes', [
            'conta_id' => $cartao, 'data' => '2026-09-20', 'valor' => '-120.00',
            'descricao' => 'Pagamento no cartão', 'natureza' => 'compra_cartao',
        ])->assertCreated();

        $this->withToken($token)->getJson('/api/v1/transacoes?forma_pagamento=debito')
            ->assertOk()->assertJsonCount(1, 'dados')
            ->assertJsonPath('dados.0.descricao_original', 'Pagamento no débito');
        $this->withToken($token)->getJson('/api/v1/transacoes?forma_pagamento=cartao_credito')
            ->assertOk()->assertJsonCount(1, 'dados')
            ->assertJsonPath('dados.0.descricao_original', 'Pagamento no cartão');
        $this->withToken($token)->getJson('/api/v1/transacoes?forma_pagamento=dinheiro')
            ->assertUnprocessable();
    }

    public function test_historico_ordena_por_data_e_preserva_desempate_na_paginacao(): void
    {
        $token = $this->autenticar('historico-por-data@exemplo.test');
        $conta = $this->conta($token, 'Conta cronológica');

        foreach ([
            ['2026-09-10', 'Movimentação antiga'],
            ['2026-09-12', 'Movimentação recente'],
            ['2026-09-11', 'Movimentação intermediária A'],
            ['2026-09-11', 'Movimentação intermediária B'],
        ] as [$data, $descricao]) {
            $this->withToken($token)->postJson('/api/v1/transacoes', [
                'conta_id' => $conta, 'data' => $data, 'valor' => '-10.00',
                'descricao' => $descricao, 'natureza' => 'saida',
            ])->assertCreated();
        }

        $consulta = '/api/v1/transacoes?ordem=desc&limite=2';
        $primeira = $this->withToken($token)->getJson($consulta)->assertOk()
            ->assertJsonPath('dados.0.descricao_original', 'Movimentação recente')
            ->assertJsonPath('dados.1.descricao_original', 'Movimentação intermediária B');

        $this->withToken($token)->getJson($consulta.'&apos='.$primeira->json('proximo_cursor'))
            ->assertOk()
            ->assertJsonPath('dados.0.descricao_original', 'Movimentação intermediária A')
            ->assertJsonPath('dados.1.descricao_original', 'Movimentação antiga')
            ->assertJsonPath('proximo_cursor', null);
    }

    public function test_movimentacao_confirmada_pode_ser_editada_com_auditoria(): void
    {
        $token = $this->autenticar('edicao@exemplo.test');
        $origem = $this->conta($token, 'Conta original');
        $destino = $this->conta($token, 'Conta corrigida');
        $categoria = $this->withToken($token)->postJson('/api/v1/categorias', [
            'nome' => 'Salário', 'tipo' => 'receita',
        ])->assertCreated()->json('id');
        $transacao = $this->withToken($token)->postJson('/api/v1/transacoes', [
            'conta_id' => $origem, 'data' => '2026-09-10', 'valor' => '-25.00',
            'descricao' => 'Valor incorreto', 'natureza' => 'saida',
        ])->assertCreated()->json('id');

        $this->withToken($token)->patchJson("/api/v1/transacoes/$transacao", [
            'conta_id' => $destino, 'data' => '2026-09-11', 'valor' => '250.50',
            'descricao' => 'Salário corrigido', 'natureza' => 'entrada',
            'categoria_id' => $categoria,
        ])->assertOk()
            ->assertJsonPath('conta_id', $destino)
            ->assertJsonPath('valor', 250.5)
            ->assertJsonPath('descricao_original', 'Salário corrigido');

        $this->assertDatabaseHas('transacoes', [
            'uuid' => $transacao, 'usuario_id' => 1,
            'conta_id' => (int) DB::table('contas')->where('uuid', $destino)->value('id'),
            'categoria_id' => (int) DB::table('categorias')->where('uuid', $categoria)->value('id'),
            'data_efetiva' => '2026-09-11',
            'valor' => 250.50, 'natureza' => 'entrada',
        ]);
        $this->assertDatabaseHas('auditoria_financeira', [
            'usuario_id' => 1, 'acao' => 'transacao_editada',
            'entidade' => 'transacoes',
            'entidade_id' => (int) DB::table('transacoes')->where('uuid', $transacao)->value('id'),
        ]);
    }

    public function test_edicao_de_movimentacao_valida_propriedade_campos_e_vinculos(): void
    {
        $ana = $this->autenticar('edicao-ana@exemplo.test');
        $contaAna = $this->conta($ana, 'Conta da Ana');
        $transacao = $this->withToken($ana)->postJson('/api/v1/transacoes', [
            'conta_id' => $contaAna, 'data' => '2026-09-10', 'valor' => '-25.00',
            'descricao' => 'Compra', 'natureza' => 'saida',
        ])->assertCreated()->json('id');
        $dados = [
            'conta_id' => $contaAna, 'data' => '2026-09-11', 'valor' => '-20.00',
            'descricao' => 'Compra corrigida', 'natureza' => 'saida', 'categoria_id' => null,
        ];

        $bia = $this->autenticar('edicao-bia@exemplo.test');
        $this->withToken($bia)->patchJson("/api/v1/transacoes/$transacao", $dados)->assertNotFound();
        $this->withToken($ana)->patchJson("/api/v1/transacoes/$transacao", $dados + ['campo_indevido' => true])
            ->assertUnprocessable();

        $this->withToken($ana)->postJson("/api/v1/transacoes/$transacao/estornos", [
            'data' => '2026-09-12', 'motivo' => 'Compra cancelada',
        ])->assertCreated();
        $this->withToken($ana)->patchJson("/api/v1/transacoes/$transacao", $dados)->assertConflict();
        $this->withToken($ana)->getJson('/api/v1/transacoes')
            ->assertOk()->assertJsonFragment(['id' => $transacao, 'editavel' => false]);
    }

    public function test_edicao_importada_preserva_a_conta_e_o_vinculo_com_o_lote(): void
    {
        $token = $this->autenticar('edicao-importada@exemplo.test');
        $origem = $this->conta($token, 'Conta importada');
        $destino = $this->conta($token, 'Outra conta');
        $csv = "data,descricao,valor,id_bancario\n2026-09-05,Mercado,-50.00,edicao-1\n";
        $lote = $this->withToken($token)->post('/api/v1/importacoes', [
            'conta_id' => $origem,
            'arquivo' => UploadedFile::fake()->createWithContent('edicao.csv', $csv),
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $this->withToken($token)->postJson("/api/v1/importacoes/$lote/confirmacao", [
            'instituicao' => 'Banco Teste',
        ])->assertOk();
        $transacao = $this->withToken($token)->getJson('/api/v1/transacoes')
            ->assertOk()->assertJsonPath('dados.0.editavel', true)->json('dados.0.id');
        $dados = [
            'conta_id' => $destino, 'data' => '2026-09-06', 'valor' => '-45.00',
            'descricao' => 'Mercado corrigido', 'natureza' => 'saida', 'categoria_id' => null,
        ];

        $this->withToken($token)->patchJson("/api/v1/transacoes/$transacao", $dados)->assertConflict();
        $dados['conta_id'] = $origem;
        $this->withToken($token)->patchJson("/api/v1/transacoes/$transacao", $dados)
            ->assertOk()->assertJsonPath('lote_id', $lote)->assertJsonPath('origem', 'importacao');
    }

    public function test_contas_sao_isoladas_e_ajustes_exigem_motivo(): void
    {
        $ana = $this->autenticar('ana@exemplo.test');
        $conta = $this->conta($ana, 'Conta da Ana');
        $bia = $this->autenticar('bia@exemplo.test');
        $this->withToken($bia)->getJson('/api/v1/contas')->assertOk()->assertJsonCount(0, 'dados');
        $this->withToken($bia)->postJson("/api/v1/contas/$conta/ajustes", [
            'data' => '2026-09-02', 'valor' => '20.00', 'motivo' => 'Conciliação inicial',
        ])->assertNotFound();
        $this->withToken($ana)->postJson("/api/v1/contas/$conta/ajustes", [
            'data' => '2026-09-02', 'valor' => '20.00', 'motivo' => 'Conciliação inicial',
        ])->assertCreated();
        $this->withToken($ana)->getJson('/api/v1/resumo?inicio=2026-09-01&fim=2026-09-30')
            ->assertOk()->assertJsonPath('consolidado.saldo_final', '1020.00')
            ->assertJsonPath('consolidado.ajustes', '20.00')
            ->assertJsonPath('consolidado.receitas', '0.00');
    }

    public function test_transferencia_entre_contas_nao_cria_receita_ou_despesa(): void
    {
        $token = $this->autenticar('ana@exemplo.test');
        $origem = $this->conta($token, 'Origem');
        $destino = $this->conta($token, 'Destino');
        $this->withToken($token)->postJson('/api/v1/transferencias', [
            'origem_id' => $origem, 'destino_id' => $destino,
            'data' => '2026-09-05', 'valor' => '150.00', 'descricao' => 'Transferência própria',
        ])->assertCreated();
        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-09-01&fim=2026-09-30')
            ->assertOk()->assertJsonPath('consolidado.saldo_final', '2000.00')
            ->assertJsonPath('consolidado.receitas', '0.00')
            ->assertJsonPath('consolidado.despesas', '0.00');
    }

    public function test_importacao_csv_exige_previa_e_e_reversivel(): void
    {
        $token = $this->autenticar('ana@exemplo.test');
        $conta = $this->conta($token, 'Conta importada');
        $csv = "data;descricao;valor;id_bancario\n05/09/2026;Salario;2500,00;credito-1\n06/09/2026;Mercado;-120,40;debito-1\n";
        $lote = $this->withToken($token)->post('/api/v1/importacoes', [
            'conta_id' => $conta, 'arquivo' => UploadedFile::fake()->createWithContent('extrato.csv', $csv),
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $this->assertDatabaseCount('transacoes', 0);
        $this->withToken($token)->getJson("/api/v1/importacoes/$lote/previa")
            ->assertOk()->assertJsonPath('total_entradas', '2500.00')
            ->assertJsonPath('total_saidas', '120.40');
        $this->withToken($token)->postJson("/api/v1/importacoes/$lote/confirmacao", [
            'instituicao' => 'Banco Teste',
        ])->assertOk()->assertJsonPath('importadas', 2);
        $this->assertDatabaseCount('transacoes', 2);
        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-09-01&fim=2026-09-30')
            ->assertOk()->assertJsonPath('consolidado.saldo_final', '3379.60');
        $this->withToken($token)->postJson("/api/v1/importacoes/$lote/reversao", [
            'confirmar' => true, 'motivo' => 'Arquivo importado por engano',
        ])->assertOk()->assertJsonPath('transacoes_revertidas', 2);
        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-09-01&fim=2026-09-30')
            ->assertOk()->assertJsonPath('consolidado.saldo_final', '1000.00');
    }

    public function test_importacao_contabiliza_todas_as_linhas_do_periodo_sem_corte_da_conta(): void
    {
        $token = $this->autenticar('periodo@exemplo.test');
        $conta = $this->withToken($token)->postJson('/api/v1/contas', [
            'nome' => 'Conta com data-base', 'tipo' => 'corrente', 'moeda' => 'BRL',
            'saldo_inicial' => '1000.00',
        ])->assertCreated()->json('id');
        $categoria = $this->withToken($token)->postJson('/api/v1/categorias', [
            'nome' => 'Compras', 'tipo' => 'despesa',
        ])->assertCreated()->json('id');
        $csv = "data,descricao,valor,id_bancario\n2026-09-10,Compra anterior ao saldo,-80.00,antiga-1\n2026-09-21,Compra posterior ao saldo,-20.00,atual-1\n";
        $lote = $this->withToken($token)->post('/api/v1/importacoes', [
            'conta_id' => $conta, 'arquivo' => UploadedFile::fake()->createWithContent('periodo.csv', $csv),
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');

        $previa = $this->withToken($token)->getJson("/api/v1/importacoes/$lote/previa")
            ->assertOk()
            ->assertJsonPath('linhas.0.situacao_previa', 'pronta')
            ->assertJsonPath('linhas.1.situacao_previa', 'pronta')
            ->assertJsonPath('total_saidas', '100.00');

        $this->withToken($token)->postJson("/api/v1/importacoes/$lote/confirmacao", [
            'instituicao' => 'Banco Teste',
            'decisoes' => collect($previa->json('linhas'))->map(fn (array $linha) => [
                'linha_id' => $linha['id'], 'decisao' => 'importar', 'categoria_id' => $categoria,
            ])->all(),
        ])->assertOk()->assertJsonPath('importadas', 2)->assertJsonPath('ignoradas', 0);

        $this->assertDatabaseHas('transacoes', ['descricao_original' => 'Compra posterior ao saldo']);
        $this->assertDatabaseHas('transacoes', ['descricao_original' => 'Compra anterior ao saldo']);
        $this->withToken($token)->getJson('/api/v1/transacoes?inicio=2026-09-01&fim=2026-09-18')
            ->assertOk()->assertJsonPath('dados.0.descricao_original', 'Compra anterior ao saldo');
        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-09-01&fim=2026-09-30')
            ->assertOk()
            ->assertJsonPath('consolidado.saldo_final', '900.00')
            ->assertJsonPath('consolidado.saidas', '100.00')
            ->assertJsonPath('consolidado.despesas', '100.00');
    }

    public function test_duplicidade_sem_id_exige_decisao_e_nao_grava_parcialmente(): void
    {
        $token = $this->autenticar('ana@exemplo.test');
        $conta = $this->conta($token, 'Conta importada');
        $primeiro = "data,descricao,valor\n2026-09-05,Mercado,-50.00\n";
        $segundo = "data,descricao,valor\n2026-09-05,Mercado,-50.00\n2026-09-06,Farmacia,-20.00\n";
        $lote1 = $this->withToken($token)->post('/api/v1/importacoes', [
            'conta_id' => $conta, 'arquivo' => UploadedFile::fake()->createWithContent('a.csv', $primeiro),
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $this->withToken($token)->postJson("/api/v1/importacoes/$lote1/confirmacao", ['instituicao' => 'Banco Teste'])->assertOk();
        $lote2 = $this->withToken($token)->post('/api/v1/importacoes', [
            'conta_id' => $conta, 'arquivo' => UploadedFile::fake()->createWithContent('b.csv', $segundo),
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $previa = $this->withToken($token)->getJson("/api/v1/importacoes/$lote2/previa")
            ->assertOk()->assertJsonPath('linhas.0.situacao_previa', 'possivel_duplicidade');
        $this->withToken($token)->postJson("/api/v1/importacoes/$lote2/confirmacao", ['instituicao' => 'Banco Teste'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('transacoes', 1);
        $this->withToken($token)->postJson("/api/v1/importacoes/$lote2/confirmacao", [
            'instituicao' => 'Banco Teste',
            'decisoes' => [[
                'linha_id' => $previa->json('linhas.0.id'), 'decisao' => 'vincular',
                'transacao_candidata_id' => $previa->json('linhas.0.transacao_candidata_id'),
            ]],
        ])->assertOk()->assertJsonPath('importadas', 1);
        $this->assertDatabaseCount('transacoes', 2);
    }

    public function test_compra_no_cartao_e_pagamento_da_fatura_nao_duplicam_despesa(): void
    {
        $token = $this->autenticar('ana@exemplo.test');
        $corrente = $this->conta($token, 'Corrente');
        $cartao = $this->withToken($token)->postJson('/api/v1/contas', [
            'nome' => 'Cartão', 'tipo' => 'cartao_credito', 'conta_pagamento_id' => $corrente,
            'limite_credito' => '5000.00', 'dia_fechamento' => 10, 'dia_vencimento' => 17,
        ])->assertCreated()->json('id');
        $categoria = $this->withToken($token)->postJson('/api/v1/categorias', [
            'nome' => 'Compras', 'tipo' => 'despesa',
        ])->assertCreated()->json('id');
        $this->withToken($token)->postJson('/api/v1/transacoes', [
            'conta_id' => $cartao, 'data' => '2026-09-03', 'valor' => '-100.00',
            'descricao' => 'Compra no cartão', 'natureza' => 'compra_cartao', 'categoria_id' => $categoria,
        ])->assertCreated();
        app(ServicoFaturasCartao::class)->fecharTodas('2026-09-10');
        $fatura = DB::table('faturas_cartao')->where('valor_total', '100.00')->value('uuid');
        $this->withToken($token)->postJson("/api/v1/contas/$cartao/faturas/$fatura/pagamento", [
            'data' => '2026-09-10', 'conta_pagamento_id' => $corrente,
        ])->assertOk();
        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-09-01&fim=2026-09-30')
            ->assertOk()->assertJsonPath('consolidado.saldo_final', '900.00')
            ->assertJsonPath('consolidado.despesas', '100.00')
            ->assertJsonPath('consolidado.saidas', '100.00');
    }

    public function test_estorno_vinculado_reduz_despesa_sem_apagar_historico(): void
    {
        $token = $this->autenticar('ana@exemplo.test');
        $conta = $this->conta($token, 'Corrente');
        $categoria = $this->withToken($token)->postJson('/api/v1/categorias', [
            'nome' => 'Mercado', 'tipo' => 'despesa',
        ])->assertCreated()->json('id');
        $compra = $this->withToken($token)->postJson('/api/v1/transacoes', [
            'conta_id' => $conta, 'data' => '2026-09-03', 'valor' => '-100.00',
            'descricao' => 'Compra original', 'natureza' => 'saida', 'categoria_id' => $categoria,
        ])->assertCreated()->json('id');
        $this->withToken($token)->postJson("/api/v1/transacoes/$compra/estornos", [
            'data' => '2026-09-04', 'motivo' => 'Devolução do produto',
        ])->assertCreated()->assertJsonPath('transacao_relacionada_id', $compra);
        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-09-01&fim=2026-09-30')
            ->assertOk()->assertJsonPath('consolidado.saldo_final', '1000.00')
            ->assertJsonPath('consolidado.despesas', '0.00');
        $this->assertDatabaseCount('transacoes', 2);
    }

    public function test_estorno_parcial_pode_ser_complementado_sem_ultrapassar_o_valor_original(): void
    {
        $token = $this->autenticar('estorno-parcial@exemplo.test');
        $conta = $this->conta($token, 'Corrente');
        $compra = $this->withToken($token)->postJson('/api/v1/transacoes', [
            'conta_id' => $conta, 'data' => '2026-09-03', 'valor' => '-100.00',
            'descricao' => 'Compra original', 'natureza' => 'saida',
        ])->assertCreated()->json('id');

        $this->withToken($token)->postJson("/api/v1/transacoes/$compra/estornos", [
            'data' => '2026-09-04', 'valor' => '30.00', 'motivo' => 'Devolução parcial um',
        ])->assertCreated()->assertJsonPath('valor', 30);
        $this->withToken($token)->postJson("/api/v1/transacoes/$compra/estornos", [
            'data' => '2026-09-05', 'valor' => '70.00', 'motivo' => 'Devolução parcial dois',
        ])->assertCreated()->assertJsonPath('valor', 70);
        $this->withToken($token)->postJson("/api/v1/transacoes/$compra/estornos", [
            'data' => '2026-09-06', 'valor' => '0.01', 'motivo' => 'Valor excedente',
        ])->assertUnprocessable();

        $this->withToken($token)->getJson('/api/v1/transacoes')
            ->assertOk()->assertJsonFragment([
                'id' => $compra, 'valor_estornado' => '100.00',
                'valor_estornavel' => '0.00', 'estornavel' => false,
            ]);
        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-09-01&fim=2026-09-30')
            ->assertOk()->assertJsonPath('consolidado.saldo_final', '1000.00')
            ->assertJsonPath('consolidado.despesas', '0.00');
        $this->assertDatabaseCount('transacoes', 3);
    }

    public function test_estorno_parcial_de_entrada_reduz_receita_com_sinal_inverso(): void
    {
        $token = $this->autenticar('estorno-entrada@exemplo.test');
        $conta = $this->conta($token, 'Corrente');
        $entrada = $this->withToken($token)->postJson('/api/v1/transacoes', [
            'conta_id' => $conta, 'data' => '2026-09-03', 'valor' => '100.00',
            'descricao' => 'Receita original', 'natureza' => 'entrada',
        ])->assertCreated()->json('id');

        $this->withToken($token)->postJson("/api/v1/transacoes/$entrada/estornos", [
            'data' => '2026-09-04', 'valor' => '25.00', 'motivo' => 'Receita devolvida parcialmente',
        ])->assertCreated()->assertJsonPath('valor', -25);
        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-09-01&fim=2026-09-30')
            ->assertOk()->assertJsonPath('consolidado.saldo_final', '1075.00');
    }

    public function test_exclusao_logica_cancela_lancamento_preserva_auditoria_e_remove_efeito_do_saldo(): void
    {
        $token = $this->autenticar('cancelamento@exemplo.test');
        $conta = $this->conta($token, 'Corrente');
        $lancamento = $this->withToken($token)->postJson('/api/v1/transacoes', [
            'conta_id' => $conta, 'data' => '2026-09-03', 'valor' => '-125.50',
            'descricao' => 'Lançamento duplicado', 'natureza' => 'saida',
        ])->assertCreated()->json('id');

        $this->withToken($token)->deleteJson("/api/v1/transacoes/$lancamento", [
            'motivo' => 'Lançamento duplicado',
        ])->assertOk()->assertJsonPath('situacao', 'cancelada');

        $id = (int) DB::table('transacoes')->where('uuid', $lancamento)->value('id');
        $this->assertDatabaseHas('transacoes', [
            'id' => $id, 'situacao' => 'cancelada', 'valor' => '-125.50',
            'descricao_original' => 'Lançamento duplicado',
        ]);
        $this->assertDatabaseHas('auditoria_financeira', [
            'usuario_id' => 1, 'acao' => 'transacao_cancelada',
            'entidade' => 'transacoes', 'entidade_id' => $id,
        ]);
        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-09-01&fim=2026-09-30')
            ->assertOk()->assertJsonPath('consolidado.saldo_final', '1000.00')
            ->assertJsonPath('consolidado.despesas', '0.00');
        $this->withToken($token)->deleteJson("/api/v1/transacoes/$lancamento", [
            'motivo' => 'Repetição inválida',
        ])->assertConflict();
    }

    public function test_cancelamento_e_estorno_respeitam_propriedade_e_vinculos(): void
    {
        $ana = $this->autenticar('cancelamento-ana@exemplo.test');
        $origem = $this->conta($ana, 'Origem');
        $destino = $this->conta($ana, 'Destino');
        $lancamento = $this->withToken($ana)->postJson('/api/v1/transacoes', [
            'conta_id' => $origem, 'data' => '2026-09-03', 'valor' => '-50.00',
            'descricao' => 'Transferência pendente', 'natureza' => 'saida',
        ])->assertCreated()->json('id');
        $this->withToken($ana)->postJson("/api/v1/transacoes/$lancamento/contrapartida", [
            'conta_contrapartida_id' => $destino, 'natureza' => 'transferencia',
        ])->assertCreated();

        $bia = $this->autenticar('cancelamento-bia@exemplo.test');
        $this->withToken($bia)->deleteJson("/api/v1/transacoes/$lancamento", [
            'motivo' => 'Tentativa de outro usuário',
        ])->assertNotFound();
        $this->withToken($bia)->postJson("/api/v1/transacoes/$lancamento/estornos", [
            'data' => '2026-09-04', 'valor' => '10.00', 'motivo' => 'Tentativa indevida',
        ])->assertNotFound();
        $this->withToken($ana)->deleteJson("/api/v1/transacoes/$lancamento", [
            'motivo' => 'Vínculo não pode ser quebrado',
        ])->assertConflict();
    }

    public function test_transferencia_importada_reverte_as_duas_pontas(): void
    {
        $token = $this->autenticar('ana@exemplo.test');
        $origem = $this->conta($token, 'Origem');
        $destino = $this->conta($token, 'Destino');
        $csv = "data,descricao,valor,id_bancario\n2026-09-05,Transferencia,-100.00,transf-1\n";
        $lote = $this->withToken($token)->post('/api/v1/importacoes', [
            'conta_id' => $origem, 'arquivo' => UploadedFile::fake()->createWithContent('a.csv', $csv),
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $this->withToken($token)->postJson("/api/v1/importacoes/$lote/confirmacao", ['instituicao' => 'Banco Teste'])->assertOk();
        $transacao = $this->withToken($token)->getJson('/api/v1/transacoes')->assertOk()->json('dados.0.id');
        $this->withToken($token)->postJson("/api/v1/transacoes/$transacao/contrapartida", [
            'conta_contrapartida_id' => $destino, 'natureza' => 'transferencia',
        ])->assertCreated();
        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-09-01&fim=2026-09-30')
            ->assertOk()->assertJsonPath('consolidado.saldo_final', '2000.00');
        $this->withToken($token)->postJson("/api/v1/importacoes/$lote/reversao", [
            'confirmar' => true, 'motivo' => 'Importação incorreta',
        ])->assertOk();
        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-09-01&fim=2026-09-30')
            ->assertOk()->assertJsonPath('consolidado.saldo_final', '2000.00');
        $this->assertDatabaseCount('transacoes', 2);
    }

    public function test_reimportacao_do_mesmo_arquivo_recupera_linhas_ausentes_sem_duplicar_ids_bancarios(): void
    {
        $token = $this->autenticar('ana@exemplo.test');
        $conta = $this->conta($token, 'Corrente');
        $primeiro = "data,descricao,valor,id_bancario\n2026-09-05,Compra,-50.00,id-1\n2026-09-07,Cafe,-10.00,id-2\n";
        $lote1 = $this->withToken($token)->post('/api/v1/importacoes', [
            'conta_id' => $conta, 'arquivo' => UploadedFile::fake()->createWithContent('a.csv', $primeiro),
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $previa1 = $this->withToken($token)->getJson("/api/v1/importacoes/$lote1/previa")->assertOk();
        $this->withToken($token)->postJson("/api/v1/importacoes/$lote1/confirmacao", [
            'instituicao' => 'Banco Teste',
            'decisoes' => [[
                'linha_id' => $previa1->json('linhas.1.id'), 'decisao' => 'ignorar',
            ]],
        ])->assertOk()->assertJsonPath('importadas', 1)->assertJsonPath('ignoradas', 1);

        $loteIgual = $this->withToken($token)->post('/api/v1/importacoes', [
            'conta_id' => $conta, 'arquivo' => UploadedFile::fake()->createWithContent('b.csv', $primeiro),
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $this->withToken($token)->getJson("/api/v1/importacoes/$loteIgual/previa")
            ->assertOk()
            ->assertJsonPath('linhas.0.situacao_previa', 'duplicada_confirmada')
            ->assertJsonPath('linhas.1.situacao_previa', 'pronta');
        $this->withToken($token)->postJson("/api/v1/importacoes/$loteIgual/confirmacao", ['instituicao' => 'Banco Teste'])
            ->assertOk()->assertJsonPath('importadas', 1)->assertJsonPath('ignoradas', 1);

        $segundo = "data,descricao,valor,id_bancario\n2026-09-05,Compra,-50.00,id-1\n2026-09-07,Cafe,-10.00,id-2\n";
        $lote2 = $this->withToken($token)->post('/api/v1/importacoes', [
            'conta_id' => $conta, 'arquivo' => UploadedFile::fake()->createWithContent('c.csv', $segundo),
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $this->withToken($token)->postJson("/api/v1/importacoes/$lote2/confirmacao", ['instituicao' => 'Banco Teste'])
            ->assertOk()->assertJsonPath('importadas', 0)->assertJsonPath('ignoradas', 2);
        $this->assertDatabaseCount('transacoes', 2);
    }

    public function test_duas_linhas_iguais_sem_id_no_mesmo_arquivo_exigem_decisao(): void
    {
        $token = $this->autenticar('ana@exemplo.test');
        $conta = $this->conta($token, 'Corrente');
        $csv = "data,descricao,valor\n2026-09-05,Mercado,-50.00\n2026-09-05,Mercado,-50.00\n";
        $lote = $this->withToken($token)->post('/api/v1/importacoes', [
            'conta_id' => $conta, 'arquivo' => UploadedFile::fake()->createWithContent('duplicadas.csv', $csv),
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $previa = $this->withToken($token)->getJson("/api/v1/importacoes/$lote/previa")
            ->assertOk()->assertJsonPath('linhas.1.situacao_previa', 'possivel_duplicidade');
        $this->withToken($token)->postJson("/api/v1/importacoes/$lote/confirmacao", [
            'instituicao' => 'Banco Teste',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('transacoes', 0);
        $this->withToken($token)->postJson("/api/v1/importacoes/$lote/confirmacao", [
            'instituicao' => 'Banco Teste',
            'decisoes' => [[
                'linha_id' => $previa->json('linhas.1.id'), 'decisao' => 'ignorar',
            ]],
        ])->assertOk()->assertJsonPath('importadas', 1)->assertJsonPath('ignoradas', 1);
    }
}
