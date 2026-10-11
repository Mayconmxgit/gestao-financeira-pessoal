<?php

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
