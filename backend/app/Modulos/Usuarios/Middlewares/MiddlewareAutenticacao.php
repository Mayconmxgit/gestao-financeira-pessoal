<?php

namespace App\Modulos\Usuarios\Middlewares;

use App\Modulos\Usuarios\Repositorios\RepositorioUsuario;
use App\Modulos\Usuarios\Servicos\ServicoToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class MiddlewareAutenticacao
{
    public function __construct(private ServicoToken $tokens, private RepositorioUsuario $usuarios) {}

    public function handle(Request $requisicao, Closure $proximo): Response
    {
        $dados = $this->tokens->verificar((string) $requisicao->bearerToken());
        $usuario = $dados === null ? null : $this->usuarios->buscarPorId((int) $dados->sub);
        // A versão invalida todos os tokens anteriores após a saída.
        if ($usuario === null || $usuario->versao_token !== ($dados->versao ?? null)) {
            return response()->json([
                'codigo' => 'AUTENTICACAO_NECESSARIA',
                'mensagem' => 'Autenticação necessária.',
                'rastreamento' => (string) Str::uuid(),
            ], 401);
        }

        $requisicao->attributes->set('usuario_atual', $usuario);

        return $proximo($requisicao);
    }
}
