<?php

namespace Tests\Feature;

use App\Modulos\Usuarios\Modelos\Usuario;
use App\Modulos\Usuarios\Servicos\ServicoToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AutenticacaoUsuarioTeste extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('autenticacao.segredo', base64_encode(random_bytes(32)));
    }

    public function test_cadastro_autenticacao_e_saida_invalidam_o_token(): void
    {
        $cadastro = $this->postJson('/api/v1/usuarios', [
            'nome' => 'Pessoa Teste', 'email' => 'pessoa@exemplo.test', 'senha' => 'SenhaForte12345!',
        ])->assertCreated()
            ->assertJsonPath('usuario.email', 'pessoa@exemplo.test')
            ->assertJsonStructure(['token', 'token_renovacao', 'renovacao_expira_em_segundos']);

        $token = $cadastro->json('token');
        $this->withToken($token)->getJson('/api/v1/usuarios/eu')->assertOk();
        $this->withToken($token)->postJson('/api/v1/sair')->assertOk();
        $this->withToken($token)->getJson('/api/v1/usuarios/eu')->assertUnauthorized();
        $this->postJson('/api/v1/entrar', [
            'email' => 'pessoa@exemplo.test', 'senha' => 'SenhaForte12345!',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_token_expirado_e_renovado_sem_perder_a_sessao(): void
    {
        config()->set('autenticacao.duracao_minutos', -1);
        config()->set('autenticacao.renovacao_dias', 7);
        $cadastro = $this->postJson('/api/v1/usuarios', [
            'nome' => 'Pessoa Teste', 'email' => 'pessoa@exemplo.test', 'senha' => 'SenhaForte12345!',
        ])->assertCreated();

        $this->withToken($cadastro->json('token'))->getJson('/api/v1/usuarios/eu')->assertUnauthorized();
        config()->set('autenticacao.duracao_minutos', 15);

        $renovacao = $this->postJson('/api/v1/renovar', [
            'token' => $cadastro->json('token_renovacao'),
        ])->assertOk()->assertJsonStructure(['token', 'token_renovacao']);

        $novoToken = $renovacao->json('token');
        $this->withToken($novoToken)->getJson('/api/v1/usuarios/eu')->assertOk();
        $this->postJson('/api/v1/renovar', ['token' => $novoToken])->assertUnauthorized();

        $this->withToken($novoToken)->postJson('/api/v1/sair')->assertOk();
        $this->postJson('/api/v1/renovar', [
            'token' => $renovacao->json('token_renovacao'),
        ])->assertUnauthorized();
    }

    public function test_entrada_invalida_e_campos_inesperados_sao_recusados(): void
    {
        $this->postJson('/api/v1/entrar', [
            'email' => 'inexistente@exemplo.test', 'senha' => 'SenhaForte12345!',
        ])->assertUnprocessable()->assertJsonPath('erros.credenciais.0', 'Credenciais inválidas.');

        $this->postJson('/api/v1/usuarios', [
            'nome' => 'Pessoa Teste', 'email' => 'pessoa@exemplo.test',
            'senha' => 'SenhaForte12345!', 'papel' => 'administrador',
        ])->assertUnprocessable();

        $this->withToken('token.invalido')->getJson('/api/v1/usuarios/eu')->assertUnauthorized();
    }

    public function test_cadastro_exige_oito_caracteres_maiuscula_minuscula_e_especial(): void
    {
        foreach (['Aa!aaaa', 'abcdefgh!', 'ABCDEFGH!', 'Abcdefgh'] as $indice => $senhaInvalida) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($indice + 1)])
                ->postJson('/api/v1/usuarios', [
                    'nome' => 'Pessoa Teste',
                    'email' => 'pessoa@exemplo.test',
                    'senha' => $senhaInvalida,
                ])->assertUnprocessable()
                ->assertJsonPath(
                    'erros.senha.0',
                    'A senha deve ter no mínimo 8 caracteres, com letra maiúscula, letra minúscula e caractere especial.',
                );
        }

        $this->postJson('/api/v1/usuarios', [
            'nome' => 'Pessoa Teste',
            'email' => 'pessoa@exemplo.test',
            'senha' => 'Abcdefg!',
        ])->assertCreated();
    }

    public function test_listagem_exige_administrador_e_usa_cursor(): void
    {
        $administrador = Usuario::query()->create([
            'nome' => 'Administração', 'email' => 'admin@exemplo.test',
            'senha' => Hash::driver('argon2id')->make('SenhaForte12345!'),
        ]);
        Usuario::query()->whereKey($administrador->id)->update(['papel' => 'administrador']);
        Usuario::query()->create([
            'nome' => 'Segundo', 'email' => 'segundo@exemplo.test',
            'senha' => Hash::driver('argon2id')->make('SenhaForte12345!'),
        ]);
        $administrador->refresh();
        $token = app(ServicoToken::class)->emitir($administrador);

        $primeira = $this->withToken($token)->getJson('/api/v1/usuarios?limite=1')
            ->assertOk()->assertJsonCount(1, 'dados');
        $cursor = $primeira->json('proximo_cursor');
        $this->withToken($token)->getJson('/api/v1/usuarios?limite=1&apos='.$cursor)
            ->assertOk()->assertJsonPath('proximo_cursor', null);

        $usuarioComum = Usuario::query()->where('email', 'segundo@exemplo.test')->firstOrFail();
        $tokenComum = app(ServicoToken::class)->emitir($usuarioComum);
        $this->withToken($tokenComum)->getJson('/api/v1/usuarios')->assertForbidden();
    }

    public function test_configuracao_ausente_nao_cria_usuario(): void
    {
        config()->set('autenticacao.segredo', null);

        $this->postJson('/api/v1/usuarios', [
            'nome' => 'Pessoa Teste', 'email' => 'pessoa@exemplo.test', 'senha' => 'SenhaForte12345!',
        ])->assertStatus(500)->assertDontSee('JWT_SEGREDO_BASE64');

        $this->assertDatabaseCount('usuarios', 0);
    }
}
