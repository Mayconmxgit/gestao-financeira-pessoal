<?php

namespace App\Modulos\Usuarios\Middlewares;

use App\Modulos\Assinaturas\Servicos\ServicoAcessoAssinatura;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MiddlewareAssinaturaAtiva
{
    public function __construct(private ServicoAcessoAssinatura $acesso) {}

    public function handle(Request $requisicao, Closure $proximo, ?string $recurso = null): Response
    {
        if (! config('assinaturas.acesso_obrigatorio')) {
            return $proximo($requisicao);
        }
        $usuario = $requisicao->attributes->get('usuario_atual');
        if ($usuario->papel === 'administrador') {
            return $proximo($requisicao);
        }

        abort_unless($this->acesso->possuiAcesso((int) $usuario->id), 402);
        abort_if($recurso !== null && ! $this->acesso->possuiRecurso((int) $usuario->id, $recurso), 403, 'RECURSO_NAO_INCLUIDO');

        return $proximo($requisicao);
    }
}
