<?php

namespace App\Modulos\Usuarios\Middlewares;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MiddlewareAdministrador
{
    public function handle(Request $requisicao, Closure $proximo): Response
    {
        abort_unless($requisicao->attributes->get('usuario_atual')?->papel === 'administrador', 403);

        return $proximo($requisicao);
    }
}
