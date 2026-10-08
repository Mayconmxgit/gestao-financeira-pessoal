<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ContratoHttpContasTeste extends TestCase
{
    private const CONTA_INEXISTENTE = '00000000-0000-4000-8000-000000000000';

    public function test_options_da_conta_anuncia_somente_patch(): void
    {
        $this->call('OPTIONS', '/api/v1/contas/'.self::CONTA_INEXISTENTE)
            ->assertOk()
            ->assertHeader('Allow', 'PATCH');
    }

    public function test_metodos_nao_implementados_retornam_405_com_o_contrato_correto(): void
    {
        foreach (['GET', 'DELETE'] as $metodo) {
            $this->json($metodo, '/api/v1/contas/'.self::CONTA_INEXISTENTE)
                ->assertStatus(405)
                ->assertHeader('Allow', 'PATCH');
        }
    }

    public function test_preflight_cors_permite_patch(): void
    {
        config()->set('cors.allowed_origins', ['http://localhost:3000']);

        $this->call('OPTIONS', '/api/v1/contas/'.self::CONTA_INEXISTENTE, server: [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Authorization, Content-Type',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Methods', 'GET, POST, PATCH, DELETE, OPTIONS');
    }
}
