<?php

use App\Modulos\Administracao\Servicos\ServicoSnapshotAdministrativo;
use App\Modulos\Financeiro\Cartoes\ServicoFaturasCartao;
use App\Modulos\Financeiro\Planejamento\ServicoPagamentosRecorrentes;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    DB::table('recuperacoes_acesso')->where('expira_em', '<', now())->delete();
})->daily()->name('limpeza-dados-temporarios')->withoutOverlapping();

Artisan::command('financeiro:processar-recorrencias', function (ServicoPagamentosRecorrentes $servico): void {
    $processadas = $servico->processarAutomaticas(now()->toDateString());
    $this->info("Cobranças recorrentes processadas: {$processadas}");
})->purpose('Lança no cartão as cobranças recorrentes automáticas vencidas');

Schedule::command('financeiro:processar-recorrencias')
    ->hourly()->name('cobrancas-recorrentes-automaticas')->withoutOverlapping();

Artisan::command('financeiro:fechar-faturas-cartao', function (ServicoFaturasCartao $servico): void {
    $fechadas = $servico->fecharTodas(now(config('app.timezone'))->toDateString());
    $this->info("Faturas fechadas: {$fechadas}");
})->purpose('Fecha faturas de cartão e atualiza vencimentos');

Schedule::command('financeiro:fechar-faturas-cartao')
    ->dailyAt('00:05')->timezone('America/Sao_Paulo')->name('fechamento-faturas-cartao')->withoutOverlapping();

Artisan::command('admin:capturar-metricas', function (ServicoSnapshotAdministrativo $servico): void {
    $servico->capturar(now(config('app.timezone')));
    $this->info('Snapshot administrativo atualizado.');
})->purpose('Captura métricas agregadas e idempotentes do backoffice');

Schedule::command('admin:capturar-metricas')->dailyAt('00:10')->timezone('America/Sao_Paulo')
    ->name('metricas-administrativas-diarias')->withoutOverlapping();
