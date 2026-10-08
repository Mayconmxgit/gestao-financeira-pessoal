<?php

namespace Tests\Unit;

use App\Suporte\ClienteIp;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClienteIpTeste extends TestCase
{
    public function test_aceita_ip_encaminhado_apenas_do_proxy_local(): void
    {
        $requisicao = Request::create('/', 'GET', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_VIDA_CLIENTE_IP' => '203.0.113.15',
        ]);

        self::assertSame('203.0.113.15', ClienteIp::obter($requisicao));
    }

    #[DataProvider('cabecalhosNaoConfiaveis')]
    public function test_ignora_cabecalho_forjado_ou_invalido(string $origem, string $cabecalho): void
    {
        $requisicao = Request::create('/', 'GET', server: [
            'REMOTE_ADDR' => $origem,
            'HTTP_X_VIDA_CLIENTE_IP' => $cabecalho,
        ]);

        self::assertSame($origem, ClienteIp::obter($requisicao));
    }

    public static function cabecalhosNaoConfiaveis(): array
    {
        return [
            'origem externa' => ['198.51.100.20', '203.0.113.15'],
            'valor invalido' => ['127.0.0.1', '203.0.113.15, 198.51.100.20'],
        ];
    }
}
