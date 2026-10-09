<?php

namespace Tests\Feature;

use App\Modulos\Usuarios\Modelos\Usuario;
use App\Modulos\Usuarios\Servicos\ServicoToken;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class PlanejamentoFinanceiroTeste extends TestCase
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

    public function test_pagamento_recorrente_so_afeta_saldo_apos_confirmacao_e_pode_ser_editado(): void
    {
        $token = $this->autenticar('recorrencia@exemplo.test');
        $conta = $this->conta($token, 'Conta principal');
        $categoria = $this->categoria($token, 'Moradia');

        $id = $this->withToken($token)->postJson('/api/v1/pagamentos-recorrentes', [
            'conta_id' => $conta, 'categoria_id' => $categoria, 'descricao' => 'Aluguel',
            'valor' => '950.00', 'periodicidade' => 'mensal', 'primeiro_vencimento' => '2027-01-31',
            'data_fim' => null,
        ])->assertCreated()->assertJsonPath('moeda', 'BRL')->json('id');

        $this->assertDatabaseCount('transacoes', 0);
        $this->withToken($token)->patchJson("/api/v1/pagamentos-recorrentes/$id", [
            'conta_id' => $conta, 'categoria_id' => $categoria, 'descricao' => 'Aluguel reajustado',
            'valor' => '1000.00', 'periodicidade' => 'mensal', 'proximo_vencimento' => '2027-01-31',
            'data_fim' => null, 'situacao' => 'ativo',
        ])->assertOk()->assertJsonPath('descricao', 'Aluguel reajustado');

        $this->withToken($token)->postJson("/api/v1/pagamentos-recorrentes/$id/pagamentos", [
            'data' => '2027-01-30',
        ])->assertCreated()
            ->assertJsonPath('pagamento.proximo_vencimento', '2027-02-28')
            ->assertJsonPath('transacao.valor', -1000);
        $this->withToken($token)->patchJson("/api/v1/pagamentos-recorrentes/$id", [
            'conta_id' => $conta, 'categoria_id' => $categoria, 'descricao' => 'Aluguel reajustado',
            'valor' => '1000.00', 'periodicidade' => 'mensal', 'proximo_vencimento' => '2027-02-28',
            'data_fim' => null, 'situacao' => 'ativo',
        ])->assertOk();
        $this->withToken($token)->postJson("/api/v1/pagamentos-recorrentes/$id/pagamentos", [
            'data' => '2027-02-28',
        ])->assertCreated()->assertJsonPath('pagamento.proximo_vencimento', '2027-03-31');

        $this->assertDatabaseHas('transacoes', [
            'usuario_id' => $this->usuarioId($token), 'descricao_original' => 'Aluguel reajustado',
            'valor' => '-1000.00', 'moeda' => 'BRL', 'situacao' => 'confirmada',
        ]);
        $this->assertDatabaseHas('execucoes_pagamentos_recorrentes', [
            'pagamento_recorrente_id' => (int) DB::table('pagamentos_recorrentes')->where('uuid', $id)->value('id'),
            'vencimento' => '2027-01-31', 'pago_em' => '2027-01-30',
        ]);
    }

    public function test_recorrencia_exige_conta_em_reais_e_respeita_propriedade(): void
    {
        $dono = $this->autenticar('dono-recorrencia@exemplo.test');
        $outro = $this->autenticar('outro-recorrencia@exemplo.test');
        $conta = $this->conta($dono, 'Conta principal');
        $dolar = $this->conta($dono, 'Conta em dólar', 'USD');
        $id = $this->recorrencia($dono, $conta);

        $this->withToken($outro)->patchJson("/api/v1/pagamentos-recorrentes/$id", $this->dadosRecorrencia($conta))
            ->assertNotFound();
        $this->withToken($dono)->postJson('/api/v1/pagamentos-recorrentes', $this->dadosRecorrencia($dolar))
            ->assertUnprocessable()->assertJsonPath('erros.conta_id.0', 'Selecione uma conta em reais.');
    }

    public function test_meta_em_reais_calcula_progresso_e_pode_ser_editada(): void
    {
        $token = $this->autenticar('meta@exemplo.test');
        $conta = $this->conta($token, 'Conta principal', 'BRL', 'Banco Exemplo');
        $id = $this->withToken($token)->postJson('/api/v1/metas-financeiras', [
            'nome' => 'Reserva de emergência', 'valor_objetivo' => '5000.00', 'prazo' => '2027-12-31',
        ])->assertCreated()->assertJsonPath('moeda', 'BRL')->json('id');

        $this->withToken($token)->postJson("/api/v1/metas-financeiras/$id/aportes", [
            'conta_id' => $conta, 'valor' => '1250.00', 'data' => '2026-09-24', 'observacao' => 'Primeiro aporte',
        ])->assertCreated()->assertJsonPath('meta.valor_acumulado', '1250.00')
            ->assertJsonPath('meta.percentual_concluido', '25.00')
            ->assertJsonPath('meta.aportes.0.conta_nome', 'Conta principal')
            ->assertJsonPath('meta.aportes.0.conta_instituicao', 'Banco Exemplo');

        $this->withToken($token)->patchJson("/api/v1/metas-financeiras/$id", [
            'nome' => 'Reserva financeira', 'valor_objetivo' => '2500.00',
            'prazo' => '2027-06-30', 'situacao' => 'ativa',
        ])->assertOk()->assertJsonPath('nome', 'Reserva financeira')
            ->assertJsonPath('percentual_concluido', '50.00');

        $this->withToken($token)->getJson('/api/v1/metas-financeiras')
            ->assertOk()->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.moeda', 'BRL')
            ->assertJsonPath('dados.0.aportes.0.observacao', 'Primeiro aporte')
            ->assertJsonPath('dados.0.aportes.0.conta_id', $conta);
        $this->assertDatabaseHas('transacoes', [
            'usuario_id' => $this->usuarioId($token), 'valor' => '-1250.00', 'natureza' => 'ajuste',
            'situacao' => 'confirmada', 'descricao_original' => 'Aporte para meta: Reserva de emergência',
        ]);
        $transacao = (int) DB::table('transacoes')->where('descricao_original', 'Aporte para meta: Reserva de emergência')->value('id');
        $contaInterna = (int) DB::table('contas')->where('uuid', $conta)->value('id');
        $this->assertDatabaseHas('aportes_metas', ['conta_id' => $contaInterna, 'transacao_id' => $transacao]);
        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-09-01&fim=2026-09-30&moeda=BRL')
            ->assertOk()->assertJsonPath('consolidado.saldo_final', '-250.00');
    }

    public function test_aporte_exige_conta_ativa_em_reais_do_usuario(): void
    {
        $dono = $this->autenticar('dono-meta@exemplo.test');
        $outro = $this->autenticar('outro-meta@exemplo.test');
        $contaOutro = $this->conta($outro, 'Banco de outra pessoa');
        $contaDolar = $this->conta($dono, 'Conta em dólar', 'USD');
        $meta = $this->withToken($dono)->postJson('/api/v1/metas-financeiras', [
            'nome' => 'Viagem', 'valor_objetivo' => '3000.00', 'prazo' => null,
        ])->assertCreated()->json('id');

        $dados = ['valor' => '100.00', 'data' => '2026-09-24', 'observacao' => null];
        $this->withToken($dono)->postJson("/api/v1/metas-financeiras/$meta/aportes", $dados + ['conta_id' => $contaOutro])
            ->assertUnprocessable()->assertJsonPath('erros.conta_id.0', 'O identificador informado é inválido.');
        $this->withToken($dono)->postJson("/api/v1/metas-financeiras/$meta/aportes", $dados + ['conta_id' => $contaDolar])
            ->assertUnprocessable()->assertJsonPath('erros.conta_id.0', 'Selecione uma conta ativa em reais que não seja cartão de crédito.');

        $this->assertDatabaseCount('aportes_metas', 0);
        $this->assertDatabaseCount('transacoes', 0);
    }

    public function test_resumo_projeta_compromissos_e_lancamento_futuro_nao_altera_saldo_atual(): void
    {
        CarbonImmutable::setTestNow('2026-09-25 12:00:00');
        $token = $this->autenticar('projecao@exemplo.test');
        $conta = $this->conta($token, 'Conta de projeção');
        $categoria = $this->categoria($token, 'Despesas futuras');
        $this->withToken($token)->postJson('/api/v1/pagamentos-recorrentes', [
            'conta_id' => $conta, 'categoria_id' => $categoria, 'descricao' => 'Internet',
            'valor' => '150.00', 'periodicidade' => 'mensal', 'primeiro_vencimento' => '2026-10-15',
            'data_fim' => null,
        ])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/pagamentos-recorrentes', [
            'conta_id' => $conta, 'categoria_id' => $categoria, 'descricao' => 'Aula semanal',
            'valor' => '25.00', 'periodicidade' => 'semanal', 'primeiro_vencimento' => '2026-10-02',
            'data_fim' => null,
        ])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/emprestimos', [
            'conta_recebimento_id' => $conta, 'credor' => 'Banco', 'descricao' => 'Empréstimo projetado',
            'valor_principal' => '1200.00', 'taxas_totais' => '0.00', 'taxa_juros_mensal' => '0.00',
            'sistema_amortizacao' => 'sac', 'quantidade_parcelas' => 3,
            'data_recebimento' => '2026-09-10', 'primeiro_vencimento' => '2026-10-10',
            'valor_total_manual' => null, 'afetar_saldo_recebimento' => false,
        ])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/transacoes', [
            'conta_id' => $conta, 'categoria_id' => $categoria, 'data' => '2026-10-05',
            'valor' => '-200.00', 'descricao' => 'Conta do próximo mês', 'natureza' => 'saida',
        ])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/transacoes', [
            'conta_id' => $conta, 'categoria_id' => $categoria, 'data' => '2026-11-05',
            'valor' => '-999.00', 'descricao' => 'Conta de outro período', 'natureza' => 'saida',
        ])->assertCreated();
        $outroToken = $this->autenticar('outra-projecao@exemplo.test');
        $outraConta = $this->conta($outroToken, 'Conta isolada');
        $this->withToken($outroToken)->postJson('/api/v1/transacoes', [
            'conta_id' => $outraConta, 'categoria_id' => null, 'data' => '2026-10-06',
            'valor' => '-700.00', 'descricao' => 'Gasto de outra pessoa', 'natureza' => 'saida',
        ])->assertCreated();

        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-09-01&fim=2026-09-30&moeda=BRL')
            ->assertOk()->assertJsonPath('consolidado.saldo_final', '1000.00')
            ->assertJsonPath('compromissos_proximo_mes.parcelas_emprestimos', '400.00')
            ->assertJsonPath('compromissos_proximo_mes.pagamentos_recorrentes', '275.00')
            ->assertJsonPath('compromissos_proximo_mes.lancamentos_futuros', '200.00')
            ->assertJsonPath('compromissos_proximo_mes.total', '875.00')
            ->assertJsonCount(8, 'compromissos_proximo_mes.itens')
            ->assertJsonPath('compromissos_proximo_mes.itens.1.descricao', 'Conta do próximo mês')
            ->assertJsonPath('compromissos_proximo_mes.itens.1.data', '2026-10-05')
            ->assertJsonPath('compromissos_proximo_mes.itens.1.valor', '200.00');
        $this->withToken($token)->getJson('/api/v1/resumo?inicio=2026-10-01&fim=2026-10-31&moeda=BRL')
            ->assertOk()->assertJsonPath('consolidado.saldo_final', '800.00');
    }

    private function autenticar(string $email): string
    {
        $usuario = Usuario::query()->create([
            'nome' => 'Pessoa Teste', 'email' => $email,
            'senha' => Hash::driver('argon2id')->make('SenhaForte12345!'),
        ]);

        return app(ServicoToken::class)->emitir($usuario);
    }

    private function usuarioId(string $token): int
    {
        $uuid = $this->withToken($token)->getJson('/api/v1/usuarios/eu')->assertOk()->json('id');

        return (int) Usuario::query()->where('uuid', $uuid)->value('id');
    }

    private function conta(string $token, string $nome, string $moeda = 'BRL', ?string $instituicao = null): string
    {
        return $this->withToken($token)->postJson('/api/v1/contas', [
            'nome' => $nome, 'tipo' => 'corrente', 'moeda' => $moeda,
            'saldo_inicial' => '1000.00', 'instituicao' => $instituicao,
        ])->assertCreated()->json('id');
    }

    private function categoria(string $token, string $nome): string
    {
        return $this->withToken($token)->postJson('/api/v1/categorias', [
            'nome' => $nome, 'tipo' => 'despesa',
        ])->assertCreated()->json('id');
    }

    private function recorrencia(string $token, string $conta): string
    {
        return $this->withToken($token)->postJson('/api/v1/pagamentos-recorrentes', $this->dadosRecorrencia($conta))
            ->assertCreated()->json('id');
    }

    /** @return array<string, mixed> */
    private function dadosRecorrencia(string $conta): array
    {
        return [
            'conta_id' => $conta, 'categoria_id' => null, 'descricao' => 'Internet', 'valor' => '120.00',
            'periodicidade' => 'mensal', 'primeiro_vencimento' => '2026-10-10', 'data_fim' => null,
        ];
    }
}
