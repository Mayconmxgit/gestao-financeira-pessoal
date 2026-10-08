<?php

use App\Modulos\Financeiro\Middlewares\MiddlewareIdentificadoresPublicos;
use App\Modulos\Usuarios\Middlewares\MiddlewareAdministrador;
use App\Modulos\Usuarios\Middlewares\MiddlewareAssinaturaAtiva;
use App\Modulos\Usuarios\Middlewares\MiddlewareAutenticacao;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'autenticacao' => MiddlewareAutenticacao::class,
            'administrador' => MiddlewareAdministrador::class,
            'assinatura' => MiddlewareAssinaturaAtiva::class,
            'identificadores.publicos' => MiddlewareIdentificadoresPublicos::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $excecao, Request $requisicao) {
            if (! $requisicao->is('api/*')) {
                return null;
            }

            $primeiro = collect($excecao->errors())->flatten()->first();
            $codigo = is_string($primeiro) && preg_match('/^([A-Z_]{4,})(?::|$)/', $primeiro, $encontrado)
                ? $encontrado[1] : 'DADOS_INVALIDOS';

            return response()->json([
                'codigo' => $codigo, 'mensagem' => 'Dados inválidos.',
                'rastreamento' => (string) Str::uuid(), 'erros' => $excecao->errors(),
            ], 422);
        });
        $exceptions->render(function (HttpExceptionInterface $excecao, Request $requisicao) {
            if (! $requisicao->is('api/*')) {
                return null;
            }
            $codigo = $excecao->getStatusCode();
            $recursoNaoIncluido = $codigo === 403 && $excecao->getMessage() === 'RECURSO_NAO_INCLUIDO';
            $assinaturaEmAndamento = $codigo === 409 && $excecao->getMessage() === 'ASSINATURA_EM_ANDAMENTO';
            $mensagens = [402 => 'Assinatura ativa necessária.', 403 => $recursoNaoIncluido ? 'Este recurso não está incluído no seu plano.' : 'Acesso negado.', 404 => 'Recurso não encontrado.', 409 => $assinaturaEmAndamento ? 'O usuário já possui uma assinatura em andamento.' : 'A operação conflita com o estado atual.', 429 => 'Muitas tentativas. Tente novamente mais tarde.', 503 => 'Serviço temporariamente indisponível.'];

            return response()->json([
                'codigo' => $recursoNaoIncluido ? 'RECURSO_NAO_INCLUIDO' : ($assinaturaEmAndamento ? 'ASSINATURA_EM_ANDAMENTO' : ([402 => 'ASSINATURA_NECESSARIA', 403 => 'ACESSO_NEGADO', 404 => 'NAO_ENCONTRADO', 409 => 'CONFLITO_DE_ESTADO', 429 => 'LIMITE_DE_TENTATIVAS', 503 => 'SERVICO_INDISPONIVEL'][$codigo] ?? 'SOLICITACAO_INVALIDA')),
                'mensagem' => $mensagens[$codigo] ?? 'Solicitação não permitida.',
                'rastreamento' => (string) Str::uuid(),
            ], $codigo, $excecao->getHeaders());
        });
        $exceptions->render(function (Throwable $excecao, Request $requisicao) {
            if (! $requisicao->is('api/*')) {
                return null;
            }
            $rastreamento = (string) Str::uuid();
            Log::error('Falha interna na API', ['rastreamento' => $rastreamento, 'tipo' => get_class($excecao)]);

            return response()->json(['codigo' => 'FALHA_INTERNA', 'mensagem' => 'Não foi possível concluir a solicitação.', 'rastreamento' => $rastreamento], 500);
        });
    })->create();
