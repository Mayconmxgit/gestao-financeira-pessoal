<?php

namespace Tests\Feature;

use App\Modulos\Usuarios\Modelos\Usuario;
use App\Modulos\Usuarios\Servicos\ServicoToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class RelatoriosFinanceirosTeste extends TestCase
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
            $pagadora = $this->conta($token, "$nome - pagadora");

            return $this->withToken($token)->postJson('/api/v1/contas', [
                'nome' => $nome, 'tipo' => $tipo, 'conta_pagamento_id' => $pagadora,
                'limite_credito' => '5000.00', 'dia_fechamento' => 10, 'dia_vencimento' => 17,
            ])->assertCreated()->json('id');
        }

        return $this->withToken($token)->postJson('/api/v1/contas', [
            'nome' => $nome, 'tipo' => $tipo, 'moeda' => 'BRL', 'saldo_inicial' => '1000.00',
        ])->assertCreated()->json('id');
    }

    private function categoria(string $token, string $nome): string
    {
        return $this->withToken($token)->postJson('/api/v1/categorias', [
            'nome' => $nome, 'tipo' => 'despesa',
        ])->assertCreated()->json('id');
    }

    private function lancar(string $token, string $conta, string $data, string $valor, string $natureza, ?string $categoria = null): string
    {
        return $this->withToken($token)->postJson('/api/v1/transacoes', array_filter([
            'conta_id' => $conta, 'data' => $data, 'valor' => $valor,
            'descricao' => "Lançamento $data", 'natureza' => $natureza, 'categoria_id' => $categoria,
        ]))->assertCreated()->json('id');
    }

    public function test_evolucao_mensal_preenche_lacunas_e_separa_contas_cartoes_e_nao_categorizados(): void
    {
        $token = $this->autenticar('evolucao@exemplo.test');
        $conta = $this->conta($token, 'Conta principal');
        $cartao = $this->conta($token, 'Cartão principal', 'cartao_credito');
        $alimentacao = $this->categoria($token, 'Alimentação');
        $this->lancar($token, $conta, '2026-01-10', '500.00', 'entrada');
        $despesaEstornada = $this->lancar($token, $conta, '2026-01-11', '-100.00', 'saida', $alimentacao);
        $this->lancar($token, $conta, '2026-01-12', '-25.00', 'saida');
        $this->lancar($token, $cartao, '2026-03-05', '-80.00', 'compra_cartao', $alimentacao);
        $this->withToken($token)->postJson("/api/v1/transacoes/$despesaEstornada/estornos", [
            'data' => '2026-01-20', 'motivo' => 'Compra cancelada',
        ])->assertCreated();

        $this->withToken($token)->getJson('/api/v1/relatorios/evolucao?intervalo=6m&referencia=2026-03-15&moeda=BRL')
            ->assertOk()->assertJsonPath('intervalo.inicio', '2025-10-01')
            ->assertJsonPath('intervalo.fim', '2026-03-31')
            ->assertJsonCount(6, 'periodos')
            ->assertJsonPath('categorias.0.nome', 'Alimentação')
            ->assertJsonPath('categorias.0.total', '80.00')
            ->assertJsonPath('categorias.0.pontos.3.total', '0.00')
            ->assertJsonPath('categorias.0.pontos.5.total', '80.00')
            ->assertJsonPath('nao_categorizado', '25.00')
            ->assertJsonPath('contas.0.nome', 'Conta principal')
            ->assertJsonPath('contas.0.pontos.3.entradas', '600.00')
            ->assertJsonPath('contas.0.pontos.3.saidas', '125.00')
            ->assertJsonPath('contas.0.pontos.3.saldo_final', '1475.00')
            ->assertJsonPath('cartoes.0.nome', 'Cartão principal')
            ->assertJsonPath('cartoes.0.pontos.5.gastos', '80.00')
            ->assertJsonMissingPath('categorias.0.uuid');

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $this->withToken($token)
            ->getJson('/api/v1/relatorios/evolucao?intervalo=6m&referencia=2026-03-15&moeda=BRL')->json('categorias.0.categoria_id'));
    }

    public function test_evolucao_de_trinta_dias_isola_usuario_e_valida_entradas(): void
    {
        $ana = $this->autenticar('ana-relatorio@exemplo.test');
        $contaAna = $this->conta($ana, 'Conta Ana');
        $this->lancar($ana, $contaAna, '2026-03-13', '-30.00', 'saida');
        $bia = $this->autenticar('bia-relatorio@exemplo.test');
        $contaBia = $this->conta($bia, 'Conta Bia');
        $this->lancar($bia, $contaBia, '2026-03-13', '-900.00', 'saida');

        $this->withToken($ana)->getJson('/api/v1/relatorios/evolucao?intervalo=30d&referencia=2026-03-15&moeda=BRL')
            ->assertOk()->assertJsonPath('intervalo.inicio', '2026-02-14')
            ->assertJsonPath('intervalo.fim', '2026-03-15')->assertJsonCount(30, 'periodos')
            ->assertJsonPath('contas.0.total_saidas', '30.00')->assertJsonMissing(['Conta Bia', '900.00']);

        $this->withToken($ana)->getJson('/api/v1/relatorios/evolucao?intervalo=2m&referencia=2026-03-15&moeda=BRL')->assertUnprocessable();
        $this->withToken($ana)->getJson('/api/v1/relatorios/evolucao?intervalo=6m&referencia=15-03-2026&moeda=BRL')->assertUnprocessable();
        $this->withToken($ana)->getJson('/api/v1/relatorios/evolucao?intervalo=12m&referencia=2026-03-15&moeda=BRL')
            ->assertOk()->assertJsonPath('intervalo.inicio', '2025-04-01')->assertJsonCount(12, 'periodos');
        $this->withHeader('Authorization', '')->getJson('/api/v1/relatorios/evolucao?intervalo=6m&referencia=2026-03-15&moeda=BRL')->assertUnauthorized();
    }

    public function test_comparacao_mensal_calcula_variacao_e_trata_base_zero(): void
    {
        $token = $this->autenticar('comparacao@exemplo.test');
        $conta = $this->conta($token, 'Conta comparada');
        $alimentacao = $this->categoria($token, 'Alimentação');
        $this->lancar($token, $conta, '2026-01-10', '-100.00', 'saida', $alimentacao);
        $this->lancar($token, $conta, '2026-02-10', '-150.00', 'saida', $alimentacao);

        $this->withToken($token)->getJson('/api/v1/relatorios/comparacao-mensal?mes_a=2026-01&mes_b=2026-02&moeda=BRL')
            ->assertOk()->assertJsonPath('categorias.0.valor_a', '100.00')
            ->assertJsonPath('categorias.0.valor_b', '150.00')
            ->assertJsonPath('categorias.0.variacao_valor', '50.00')
            ->assertJsonPath('categorias.0.variacao_percentual', '50.00')
            ->assertJsonPath('contas.0.saidas.variacao_percentual', '50.00')
            ->assertJsonPath('contas.0.entradas.variacao_percentual', null);

        $this->withToken($token)->getJson('/api/v1/relatorios/comparacao-mensal?mes_a=2026-13&mes_b=2026-02&moeda=BRL')->assertUnprocessable();
    }
}
