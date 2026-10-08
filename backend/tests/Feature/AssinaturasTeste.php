<?php

namespace Tests\Feature;

use App\Modulos\Assinaturas\Dominio\RecursosPlano;
use App\Modulos\Usuarios\Modelos\Usuario;
use App\Modulos\Usuarios\Servicos\ServicoToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AssinaturasTeste extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('autenticacao.segredo', base64_encode(random_bytes(32)));
    }

    public function test_painel_exige_administrador_e_preserva_preco_contratado(): void
    {
        [$admin, $tokenAdmin] = $this->usuario('admin@exemplo.test', 'administrador');
        [, $tokenUsuario] = $this->usuario('cliente@exemplo.test');
        $this->withToken($tokenUsuario)->getJson('/api/v1/admin/assinaturas/resumo')->assertForbidden();

        $plano = $this->criarPlano($tokenAdmin, '29.90');
        $this->withToken($tokenAdmin)->postJson('/api/v1/admin/assinaturas/cortesia', [
            'usuario_id' => Usuario::query()->where('email', 'cliente@exemplo.test')->value('uuid'),
            'plano_id' => $plano['id'], 'fim' => now()->addMonth()->toDateString(), 'motivo' => 'Período promocional inicial',
        ])->assertCreated()->assertJsonPath('valor', '0.00');

        $alterado = [...$this->dadosPlano('49.90'), 'nome' => 'Premium atualizado'];
        $this->withToken($tokenAdmin)->patchJson('/api/v1/admin/assinaturas/planos/'.$plano['id'], $alterado)->assertOk();
        $this->assertDatabaseHas('assinaturas', ['valor_contratado' => 0]);
        $this->assertDatabaseHas('auditoria_assinaturas', ['administrador_id' => $admin->id, 'acao' => 'plano_atualizado']);

        $this->withToken($tokenAdmin)->patchJson('/api/v1/admin/assinaturas/planos/'.$plano['id'], [
            ...$alterado, 'ativo' => false,
        ])->assertOk()->assertJsonPath('ativo', false);
        $this->withToken($tokenUsuario)->getJson('/api/v1/planos')->assertOk()->assertJsonCount(0, 'dados');
        $this->assertDatabaseHas('assinaturas', ['situacao' => 'ativa', 'valor_contratado' => 0]);
    }

    public function test_checkout_usa_gateway_sem_receber_dados_de_cartao(): void
    {
        [, $tokenAdmin] = $this->usuario('admin@exemplo.test', 'administrador');
        [, $tokenUsuario] = $this->usuario('cliente@exemplo.test');
        $plano = $this->criarPlano($tokenAdmin, '29.90');
        $this->configurarGateway();
        Http::fake(['https://api.mercadopago.com/preapproval' => Http::response([
            'id' => 'preapproval-123', 'init_point' => 'https://www.mercadopago.com.br/subscriptions/checkout?id=123',
            'status' => 'pending',
        ], 201)]);

        $this->withToken($tokenUsuario)->postJson('/api/v1/assinatura/checkout', ['plano_id' => $plano['id']])
            ->assertCreated()->assertJsonPath('url_checkout', 'https://www.mercadopago.com.br/subscriptions/checkout?id=123');
        Http::assertSent(function ($requisicao): bool {
            $dados = $requisicao->data();

            return $requisicao->url() === 'https://api.mercadopago.com/preapproval'
                && $dados['status'] === 'pending' && ! isset($dados['card_token_id'])
                && $requisicao->hasHeader('X-Idempotency-Key');
        });
        $this->assertDatabaseHas('assinaturas', ['identificador_externo' => 'preapproval-123', 'valor_contratado' => 29.90]);
    }

    public function test_checkout_desativado_ou_com_url_nao_confiavel_e_recusado(): void
    {
        [, $tokenAdmin] = $this->usuario('admin@exemplo.test', 'administrador');
        [, $tokenUsuario] = $this->usuario('cliente@exemplo.test');
        $plano = $this->criarPlano($tokenAdmin, '29.90');
        $this->withToken($tokenUsuario)->postJson('/api/v1/assinatura/checkout', ['plano_id' => $plano['id']])
            ->assertStatus(503)->assertJsonPath('codigo', 'SERVICO_INDISPONIVEL');

        $this->configurarGateway();
        Http::fake(['https://api.mercadopago.com/preapproval' => Http::response([
            'id' => 'preapproval-malicioso', 'init_point' => 'https://exemplo-malicioso.test/captura', 'status' => 'pending',
        ], 201)]);
        $this->withToken($tokenUsuario)->postJson('/api/v1/assinatura/checkout', ['plano_id' => $plano['id']])
            ->assertStatus(500)->assertJsonMissing(['url_checkout']);
        $this->assertDatabaseHas('assinaturas', ['situacao' => 'falha']);
    }

    public function test_plano_gratuito_e_ativado_sem_gateway_de_pagamento(): void
    {
        [, $tokenAdmin] = $this->usuario('admin@exemplo.test', 'administrador');
        [$usuario, $tokenUsuario] = $this->usuario('cliente@exemplo.test');
        $plano = $this->criarPlano($tokenAdmin, '0.00');
        config()->set('assinaturas.acesso_obrigatorio', true);
        Http::fake();

        $this->withToken($tokenUsuario)->getJson('/api/v1/contas')->assertStatus(402);
        $this->withToken($tokenUsuario)->postJson('/api/v1/assinatura/checkout', ['plano_id' => $plano['id']])
            ->assertCreated()->assertJsonPath('gratuito', true)->assertJsonPath('url_checkout', null);
        $this->withToken($tokenUsuario)->getJson('/api/v1/contas')->assertOk();
        $this->withToken($tokenUsuario)->getJson('/api/v1/assinatura')
            ->assertOk()->assertJsonPath('assinatura.recursos.0', RecursosPlano::CONTAS_MOVIMENTACOES);

        $this->assertDatabaseHas('assinaturas', [
            'usuario_id' => $usuario->id, 'provedor' => 'gratuito', 'situacao' => 'ativa', 'valor_contratado' => 0,
        ]);
        Http::assertNothingSent();
    }

    public function test_cortesia_substitui_plano_gratuito_ativo_e_preserva_historico(): void
    {
        [$admin, $tokenAdmin] = $this->usuario('admin@exemplo.test', 'administrador');
        [$usuario, $tokenUsuario] = $this->usuario('cliente@exemplo.test');
        $planoGratuito = $this->criarPlano($tokenAdmin, '0.00');
        $planoPremium = $this->criarPlano($tokenAdmin, '29.90');

        $this->withToken($tokenUsuario)->postJson('/api/v1/assinatura/checkout', [
            'plano_id' => $planoGratuito['id'],
        ])->assertCreated();
        $assinaturaGratuita = DB::table('assinaturas')->where('usuario_id', $usuario->id)->first();

        $this->withToken($tokenAdmin)->postJson('/api/v1/admin/assinaturas/cortesia', [
            'usuario_id' => $usuario->uuid, 'plano_id' => $planoPremium['id'],
            'fim' => now()->addMonth()->toDateString(), 'motivo' => 'Bônus de boas-vindas',
        ])->assertCreated()
            ->assertJsonPath('provedor', 'cortesia')
            ->assertJsonPath('situacao', 'ativa');

        $this->assertDatabaseHas('assinaturas', [
            'id' => $assinaturaGratuita->id, 'provedor' => 'gratuito', 'situacao' => 'cancelada',
        ]);
        $this->assertNotNull(DB::table('assinaturas')->where('id', $assinaturaGratuita->id)->value('cancelada_em'));
        $this->assertDatabaseHas('assinaturas', [
            'usuario_id' => $usuario->id, 'provedor' => 'cortesia', 'situacao' => 'ativa',
        ]);
        $this->assertDatabaseCount('assinaturas', 2);
        $this->assertDatabaseHas('auditoria_assinaturas', [
            'administrador_id' => $admin->id, 'acao' => 'cortesia_concedida',
        ]);
    }

    public function test_cortesia_nao_substitui_assinatura_paga_em_andamento(): void
    {
        [, $tokenAdmin] = $this->usuario('admin@exemplo.test', 'administrador');
        [$usuario] = $this->usuario('cliente@exemplo.test');
        $planoPremium = $this->criarPlano($tokenAdmin, '29.90');
        $plano = DB::table('planos_assinatura')->where('uuid', $planoPremium['id'])->first();
        DB::table('assinaturas')->insert([
            'uuid' => fake()->uuid(), 'usuario_id' => $usuario->id, 'plano_id' => $plano->id,
            'provedor' => 'mercado_pago', 'referencia_externa' => fake()->uuid(), 'situacao' => 'ativa',
            'valor_contratado' => '29.90', 'moeda' => 'BRL', 'periodicidade' => 'mensal', 'intervalo' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withToken($tokenAdmin)->postJson('/api/v1/admin/assinaturas/cortesia', [
            'usuario_id' => $usuario->uuid, 'plano_id' => $planoPremium['id'],
            'fim' => now()->addMonth()->toDateString(), 'motivo' => 'Bônus de boas-vindas',
        ])->assertStatus(409)
            ->assertJsonPath('codigo', 'ASSINATURA_EM_ANDAMENTO')
            ->assertJsonPath('mensagem', 'O usuário já possui uma assinatura em andamento.');

        $this->assertDatabaseCount('assinaturas', 1);
        $this->assertDatabaseHas('assinaturas', [
            'usuario_id' => $usuario->id, 'provedor' => 'mercado_pago', 'situacao' => 'ativa',
        ]);
    }

    public function test_webhook_valida_assinatura_e_processa_repeticao_uma_vez(): void
    {
        [$usuario] = $this->usuario('cliente@exemplo.test');
        $plano = $this->inserirPlano();
        DB::table('assinaturas')->insert([
            'uuid' => fake()->uuid(), 'usuario_id' => $usuario->id, 'plano_id' => $plano->id,
            'provedor' => 'mercado_pago', 'identificador_externo' => 'preapproval-123',
            'referencia_externa' => fake()->uuid(), 'situacao' => 'pendente', 'valor_contratado' => '29.90',
            'moeda' => 'BRL', 'periodicidade' => 'mensal', 'intervalo' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->configurarGateway();
        Http::fake(['https://api.mercadopago.com/preapproval/preapproval-123' => Http::response([
            'id' => 'preapproval-123', 'status' => 'authorized', 'next_payment_date' => now()->addMonth()->toIso8601String(),
        ])]);
        $cabecalhos = $this->cabecalhosWebhook('preapproval-123', 'req-123');
        $corpo = ['type' => 'subscription_preapproval', 'action' => 'updated', 'data' => ['id' => 'preapproval-123']];

        $this->withHeaders($cabecalhos)->postJson('/api/v1/webhooks/mercado-pago/assinaturas', $corpo)->assertOk();
        $this->withHeaders($cabecalhos)->postJson('/api/v1/webhooks/mercado-pago/assinaturas', $corpo)->assertOk();
        $this->assertDatabaseHas('assinaturas', ['identificador_externo' => 'preapproval-123', 'situacao' => 'ativa']);
        $this->assertDatabaseCount('eventos_webhook_assinatura', 1);
        Http::assertSentCount(1);

        $this->withHeaders(['x-request-id' => 'req-invalida', 'x-signature' => 'ts=1,v1=invalida'])
            ->postJson('/api/v1/webhooks/mercado-pago/assinaturas', $corpo)->assertForbidden();
    }

    public function test_controle_de_acesso_pode_ser_ativado_sem_bloquear_administrador(): void
    {
        [, $tokenAdmin] = $this->usuario('admin@exemplo.test', 'administrador');
        [$usuario, $tokenUsuario] = $this->usuario('cliente@exemplo.test');
        config()->set('assinaturas.acesso_obrigatorio', true);
        $this->withToken($tokenUsuario)->getJson('/api/v1/contas')->assertStatus(402);
        $this->withToken($tokenAdmin)->getJson('/api/v1/contas')->assertOk();

        $plano = $this->inserirPlano();
        DB::table('assinaturas')->insert([
            'uuid' => fake()->uuid(), 'usuario_id' => $usuario->id, 'plano_id' => $plano->id,
            'provedor' => 'cortesia', 'referencia_externa' => fake()->uuid(), 'situacao' => 'ativa',
            'valor_contratado' => '0.00', 'moeda' => 'BRL', 'periodicidade' => 'mensal', 'intervalo' => 1,
            'periodo_acesso_fim' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($tokenUsuario)->getJson('/api/v1/contas')->assertOk();
    }

    public function test_plano_basico_bloqueia_recursos_nao_contratados_no_backend(): void
    {
        [$usuario, $tokenUsuario] = $this->usuario('basico@exemplo.test');
        config()->set('assinaturas.acesso_obrigatorio', true);
        $plano = $this->inserirPlano([
            RecursosPlano::VISAO_GERAL,
            RecursosPlano::CONTAS_MOVIMENTACOES,
            RecursosPlano::IMPORTACAO_EXTRATOS,
        ]);
        DB::table('assinaturas')->insert([
            'uuid' => fake()->uuid(), 'usuario_id' => $usuario->id, 'plano_id' => $plano->id,
            'provedor' => 'gratuito', 'referencia_externa' => fake()->uuid(), 'situacao' => 'ativa',
            'valor_contratado' => '0.00', 'moeda' => 'BRL', 'periodicidade' => 'mensal', 'intervalo' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withToken($tokenUsuario)->getJson('/api/v1/contas')->assertOk();
        $this->withToken($tokenUsuario)->getJson('/api/v1/importacoes')->assertOk();
        foreach (['emprestimos', 'pagamentos-recorrentes', 'metas-financeiras'] as $rota) {
            $this->withToken($tokenUsuario)->getJson('/api/v1/'.$rota)
                ->assertForbidden()
                ->assertJsonPath('codigo', 'RECURSO_NAO_INCLUIDO');
        }
    }

    private function usuario(string $email, string $papel = 'usuario'): array
    {
        $usuario = Usuario::query()->create([
            'nome' => 'Pessoa Teste', 'email' => $email, 'senha' => Hash::driver('argon2id')->make('SenhaForte12345!'),
        ]);
        $usuario->forceFill(['papel' => $papel])->save();

        return [$usuario, app(ServicoToken::class)->emitir($usuario)];
    }

    private function criarPlano(string $token, string $valor): array
    {
        return $this->withToken($token)->postJson('/api/v1/admin/assinaturas/planos', $this->dadosPlano($valor))
            ->assertCreated()->json();
    }

    private function dadosPlano(string $valor): array
    {
        return ['nome' => 'Premium', 'descricao' => 'Recursos completos', 'valor' => $valor,
            'periodicidade' => 'mensal', 'recursos' => [RecursosPlano::CONTAS_MOVIMENTACOES], 'ativo' => true];
    }

    private function inserirPlano(?array $recursos = null): object
    {
        $id = DB::table('planos_assinatura')->insertGetId([
            'uuid' => fake()->uuid(), ...$this->dadosPlano('29.90'), 'moeda' => 'BRL', 'intervalo' => 1,
            'recursos' => json_encode($recursos ?? [RecursosPlano::CONTAS_MOVIMENTACOES]), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('planos_assinatura')->find($id);
    }

    private function configurarGateway(): void
    {
        config()->set('assinaturas.mercado_pago.ativo', true);
        config()->set('assinaturas.mercado_pago.token', 'TEST-token-seguro');
        config()->set('assinaturas.mercado_pago.segredo_webhook', 'segredo-de-teste');
    }

    private function cabecalhosWebhook(string $recurso, string $requisicao): array
    {
        $ts = (string) (time() * 1000);
        $mensagem = "id:$recurso;request-id:$requisicao;ts:$ts;";

        return ['x-request-id' => $requisicao, 'x-signature' => 'ts='.$ts.',v1='.hash_hmac('sha256', $mensagem, 'segredo-de-teste')];
    }
}
