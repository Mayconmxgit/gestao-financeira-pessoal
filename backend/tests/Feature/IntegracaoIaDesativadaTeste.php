<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class IntegracaoIaDesativadaTeste extends TestCase
{
    use RefreshDatabase;

    public function test_endpoints_do_assistente_nao_estao_disponiveis(): void
    {
        $this->getJson('/api/v1/assistente/consentimento')->assertNotFound();
        $this->postJson('/api/v1/assistente/consentimento')->assertNotFound();
        $this->postJson('/api/v1/assistente/revogacao')->assertNotFound();
        $this->getJson('/api/v1/assistente/perguntas')->assertNotFound();
        $this->postJson('/api/v1/assistente/perguntas')->assertNotFound();
        $this->getJson('/api/v1/assistente/perguntas/1')->assertNotFound();
        $this->deleteJson('/api/v1/assistente/perguntas/1')->assertNotFound();
    }
}
