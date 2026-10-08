<?php

namespace Tests\Feature;

use App\Mail\RecuperacaoAcesso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class RecuperacaoAcessoTeste extends TestCase
{
    use RefreshDatabase;

    public function test_recuperacao_revoga_token_anterior_e_e_de_uso_unico(): void
    {
        config()->set('autenticacao.segredo', base64_encode(random_bytes(32)));
        Mail::fake();
        $cadastro = $this->postJson('/api/v1/usuarios', [
            'nome' => 'Pessoa Teste', 'email' => 'pessoa@exemplo.test', 'senha' => 'SenhaForte12345!',
        ])->assertCreated();
        $tokenAntigo = $cadastro->json('token');
        $this->postJson('/api/v1/recuperar-acesso', ['email' => 'pessoa@exemplo.test'])->assertOk();
        $url = null;
        Mail::assertSent(RecuperacaoAcesso::class, function (RecuperacaoAcesso $mensagem) use (&$url): bool {
            $url = $mensagem->url;

            return true;
        });
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parametros);
        foreach (['Aa!aaaa', 'abcdefgh!', 'ABCDEFGH!', 'Abcdefgh'] as $indice => $senhaInvalida) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.($indice + 1)])
                ->postJson('/api/v1/redefinir-senha', [
                    'email' => $parametros['email'],
                    'token' => $parametros['token'],
                    'senha' => $senhaInvalida,
                ])->assertUnprocessable()
                ->assertJsonPath(
                    'erros.senha.0',
                    'A senha deve ter no mínimo 8 caracteres, com letra maiúscula, letra minúscula e caractere especial.',
                );
        }
        $this->postJson('/api/v1/redefinir-senha', [
            'email' => $parametros['email'], 'token' => $parametros['token'],
            'senha' => 'Nova!Abc',
        ])->assertOk();
        $this->withToken($tokenAntigo)->getJson('/api/v1/usuarios/eu')->assertUnauthorized();
        $this->postJson('/api/v1/redefinir-senha', [
            'email' => $parametros['email'], 'token' => $parametros['token'],
            'senha' => 'MaisUmaSenha12345!',
        ])->assertUnprocessable();
        $this->postJson('/api/v1/entrar', [
            'email' => 'pessoa@exemplo.test', 'senha' => 'Nova!Abc',
        ])->assertOk();
    }
}
