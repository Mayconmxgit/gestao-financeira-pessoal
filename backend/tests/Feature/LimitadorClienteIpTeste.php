<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LimitadorClienteIpTeste extends TestCase
{
    use RefreshDatabase;

    private const CREDENCIAIS_INVALIDAS = [
        'email' => 'inexistente@exemplo.test',
        'senha' => 'SenhaForte12345!',
    ];

    public function test_separa_o_limite_pelo_ip_validado_do_proxy_local(): void
    {
        for ($tentativa = 0; $tentativa < 5; $tentativa++) {
            $this->entrada('127.0.0.1', '203.0.113.31')->assertUnprocessable();
        }

        $this->entrada('127.0.0.1', '203.0.113.31')->assertTooManyRequests();
        $this->entrada('127.0.0.1', '203.0.113.32')->assertUnprocessable();
    }

    public function test_cabecalho_forjado_nao_contorna_o_limite(): void
    {
        for ($tentativa = 0; $tentativa < 5; $tentativa++) {
            $this->entrada('198.51.100.44', '203.0.113.'.($tentativa + 40))->assertUnprocessable();
        }

        $this->entrada('198.51.100.44', '203.0.113.99')->assertTooManyRequests();
    }

    private function entrada(string $origem, string $encaminhado)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $origem])
            ->withHeaders(['X-Vida-Cliente-IP' => $encaminhado])
            ->postJson('/api/v1/entrar', self::CREDENCIAIS_INVALIDAS);
    }
}
