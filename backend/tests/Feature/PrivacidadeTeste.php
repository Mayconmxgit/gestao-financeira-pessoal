<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PrivacidadeTeste extends TestCase
{
    use RefreshDatabase;

    public function test_exportacao_e_exclusao_ficam_restritas_ao_proprietario(): void
    {
        config()->set('autenticacao.segredo', base64_encode(random_bytes(32)));
        $ana = $this->postJson('/api/v1/usuarios', [
            'nome' => 'Ana', 'email' => 'ana@exemplo.test', 'senha' => 'SenhaForte12345!',
        ])->assertCreated()->json('token');
        $bia = $this->postJson('/api/v1/usuarios', [
            'nome' => 'Bia', 'email' => 'bia@exemplo.test', 'senha' => 'SenhaForte12345!',
        ])->assertCreated()->json('token');
        $this->withToken($ana)->postJson('/api/v1/contas', [
            'nome' => 'Conta Ana', 'tipo' => 'corrente', 'moeda' => 'BRL',
            'saldo_inicial' => '100.00',
        ])->assertCreated();
        $this->withToken($bia)->postJson('/api/v1/contas', [
            'nome' => 'Conta Bia', 'tipo' => 'corrente', 'moeda' => 'BRL',
            'saldo_inicial' => '200.00',
        ])->assertCreated();
        $plano = DB::table('planos_assinatura')->insertGetId([
            'uuid' => fake()->uuid(), 'nome' => 'Premium', 'valor' => '29.90', 'moeda' => 'BRL',
            'periodicidade' => 'mensal', 'intervalo' => 1, 'recursos' => '[]', 'ativo' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $assinatura = DB::table('assinaturas')->insertGetId([
            'uuid' => fake()->uuid(), 'usuario_id' => 1, 'plano_id' => $plano, 'provedor' => 'cortesia',
            'referencia_externa' => fake()->uuid(), 'situacao' => 'ativa', 'valor_contratado' => '0.00',
            'moeda' => 'BRL', 'periodicidade' => 'mensal', 'intervalo' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cobrancas_assinatura')->insert([
            'uuid' => fake()->uuid(), 'assinatura_id' => $assinatura, 'situacao' => 'aprovada', 'valor' => '0.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $resposta = $this->withToken($ana)->get('/api/v1/usuarios/eu/exportacao')->assertOk();
        $exportacao = json_decode($resposta->streamedContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('ana@exemplo.test', $exportacao['usuario']['email']);
        $this->assertCount(1, $exportacao['contas']);
        $this->assertSame('Conta Ana', $exportacao['contas'][0]['nome']);
        $this->assertCount(1, $exportacao['assinaturas']);
        $this->assertCount(1, $exportacao['cobrancas_assinatura']);
        $this->withToken($ana)->deleteJson('/api/v1/usuarios/eu', [
            'senha' => 'SenhaForte12345!', 'confirmar' => true,
        ])->assertOk();
        $this->withToken($ana)->getJson('/api/v1/usuarios/eu')->assertUnauthorized();
        $this->withToken($bia)->getJson('/api/v1/contas')->assertOk()->assertJsonCount(1, 'dados');
        $this->assertDatabaseCount('usuarios', 1);
        $this->assertDatabaseCount('contas', 1);
        $this->assertDatabaseCount('assinaturas', 0);
        $this->assertDatabaseCount('cobrancas_assinatura', 0);
    }
}
