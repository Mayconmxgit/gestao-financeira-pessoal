<?php

namespace App\Providers;

use App\Suporte\ClienteIp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('cadastro', fn (Request $requisicao) => Limit::perMinute(3)->by(ClienteIp::obter($requisicao)));
        RateLimiter::for('entrada', fn (Request $requisicao) => Limit::perMinute(5)->by(ClienteIp::obter($requisicao)));
        RateLimiter::for('recuperacao', fn (Request $requisicao) => Limit::perMinute(3)->by(ClienteIp::obter($requisicao)));
        RateLimiter::for('autenticado', fn (Request $requisicao) => Limit::perMinute(120)
            ->by((string) ($requisicao->attributes->get('usuario_atual')?->id ?? ClienteIp::obter($requisicao))));
        RateLimiter::for('importacao', fn (Request $requisicao) => Limit::perHour(20)
            ->by((string) ($requisicao->attributes->get('usuario_atual')?->id ?? ClienteIp::obter($requisicao))));
        RateLimiter::for('checkout-assinatura', fn (Request $requisicao) => Limit::perMinute(3)
            ->by((string) ($requisicao->attributes->get('usuario_atual')?->id ?? ClienteIp::obter($requisicao))));
        RateLimiter::for('webhook-assinatura', fn (Request $requisicao) => Limit::perMinute(120)->by(ClienteIp::obter($requisicao)));
    }
}
