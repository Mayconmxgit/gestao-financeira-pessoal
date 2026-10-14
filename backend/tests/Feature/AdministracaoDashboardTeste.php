<?php

namespace Tests\Feature;

use App\Modulos\Usuarios\Modelos\Usuario;
use App\Modulos\Usuarios\Servicos\ServicoToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdministracaoDashboardTeste extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('autenticacao.segredo', base64_encode(random_bytes(32)));
        $this->travelTo('2026-10-13 12:00:00');
    }

    public function test_dashboard_exige_administrador_e_retorna_metricas_agregadas(): void
    {
        [, $tokenAdmin] = $this->usuario('admin@exemplo.test', 'administrador');
        [$cliente, $tokenCliente] = $this->usuario('cliente@exemplo.test');
        $plano = $this->plano('Premium', '120.00', 'anual');
        $this->assinatura($cliente->id, $plano, 'mercado_pago', 'ativa', '120.00', 'anual');

        $this->withToken($tokenCliente)->getJson('/api/v1/admin/dashboard')->assertForbidden();
        $this->withToken($tokenAdmin)->getJson('/api/v1/admin/dashboard?intervalo=30d&referencia=2026-10-13')
            ->assertOk()
            ->assertJsonPath('resumo.usuarios_total', 1)
            ->assertJsonPath('resumo.assinaturas_ativas', 1)
            ->assertJsonPath('resumo.mrr_contratado', '10.00')
            ->assertJsonPath('resumo.arr_projetado', '120.00')
            ->assertJsonPath('resumo.churn_percentual', null)
            ->assertJsonStructure(['medicao_desde', 'resumo', 'crescimento', 'planos', 'alertas']);
    }

    public function test_snapshot_e_idempotente_e_relatorio_preserva_lacunas(): void
    {
        [, $tokenAdmin] = $this->usuario('admin@exemplo.test', 'administrador');
        [$cliente] = $this->usuario('cliente@exemplo.test');
        $plano = $this->plano('Premium', '29.90');
        $this->assinatura($cliente->id, $plano, 'mercado_pago', 'ativa', '29.90');

        Artisan::call('admin:capturar-metricas');
        Artisan::call('admin:capturar-metricas');

        $this->assertDatabaseCount('metricas_admin_diarias', 1);
        $this->assertDatabaseCount('metricas_admin_planos_diarias', 1);
        $this->withToken($tokenAdmin)->getJson('/api/v1/admin/relatorios/receita-recorrente?intervalo=7d&referencia=2026-10-13')
            ->assertOk()
            ->assertJsonPath('serie.6.mrr_contratado', '29.90')
            ->assertJsonPath('serie.0.mrr_contratado', null);
    }

    public function test_listagem_de_usuarios_filtra_e_nao_expoe_dados_financeiros(): void
    {
        [, $tokenAdmin] = $this->usuario('admin@exemplo.test', 'administrador');
        [$cliente] = $this->usuario('ana@exemplo.test');
        $plano = $this->plano('Essencial', '0.00');
        $this->assinatura($cliente->id, $plano, 'gratuito', 'ativa', '0.00');

        $resposta = $this->withToken($tokenAdmin)->getJson('/api/v1/admin/usuarios?busca=ana&limite=20')
            ->assertOk()->assertJsonCount(1, 'dados')
            ->assertJsonPath('dados.0.email', 'ana@exemplo.test');

        $this->assertArrayNotHasKey('saldo', $resposta->json('dados.0'));
        $this->assertArrayNotHasKey('transacoes', $resposta->json('dados.0'));

        DB::table('usuarios')->where('id', $cliente->id)->update(['nome' => '@FORMULA']);
        $csv = $this->withToken($tokenAdmin)->get('/api/v1/admin/usuarios/exportacao.csv?busca=ana')->assertOk();
        $this->assertStringContainsString("'@FORMULA", $csv->streamedContent());
    }

    public function test_exportacao_csv_neutraliza_formulas_e_rejeita_filtros_invalidos(): void
    {
        [, $tokenAdmin] = $this->usuario('admin@exemplo.test', 'administrador');
        $this->plano('=HIPERLINK("https://malicioso.test")', '29.90');

        $resposta = $this->withToken($tokenAdmin)->get('/api/v1/admin/relatorios/receita-recorrente/exportacao.csv?intervalo=30d&referencia=2026-10-13')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString("'=HIPERLINK", $resposta->streamedContent());
        $this->withToken($tokenAdmin)->getJson('/api/v1/admin/dashboard?intervalo=1d&referencia=2026-10-13')
            ->assertUnprocessable();
        $this->withToken($tokenAdmin)->getJson('/api/v1/admin/dashboard?intervalo=personalizado&de=2024-09-01&ate=2026-10-13')
            ->assertUnprocessable();
    }

    private function usuario(string $email, string $papel = 'usuario'): array
    {
        $usuario = Usuario::query()->create([
            'nome' => 'Pessoa Teste', 'email' => $email,
            'senha' => Hash::driver('argon2id')->make('SenhaForte12345!'),
        ]);
        $usuario->forceFill(['papel' => $papel])->save();

        return [$usuario, app(ServicoToken::class)->emitir($usuario)];
    }

    private function plano(string $nome, string $valor, string $periodicidade = 'mensal'): object
    {
        $id = DB::table('planos_assinatura')->insertGetId([
            'uuid' => (string) Str::uuid(), 'nome' => $nome, 'descricao' => null,
            'valor' => $valor, 'moeda' => 'BRL', 'periodicidade' => $periodicidade,
            'intervalo' => 1, 'recursos' => '[]', 'ativo' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('planos_assinatura')->find($id);
    }

    private function assinatura(
        int $usuario, object $plano, string $provedor, string $situacao,
        string $valor, string $periodicidade = 'mensal'
    ): void {
        DB::table('assinaturas')->insert([
            'uuid' => (string) Str::uuid(), 'usuario_id' => $usuario, 'plano_id' => $plano->id,
            'provedor' => $provedor, 'referencia_externa' => (string) Str::uuid(), 'situacao' => $situacao,
            'valor_contratado' => $valor, 'moeda' => 'BRL', 'periodicidade' => $periodicidade,
            'intervalo' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
