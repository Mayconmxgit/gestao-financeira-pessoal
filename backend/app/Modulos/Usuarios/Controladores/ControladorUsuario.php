<?php

namespace App\Modulos\Usuarios\Controladores;

use App\Http\Controllers\Controller;
use App\Modulos\Usuarios\Repositorios\RepositorioUsuario;
use App\Modulos\Usuarios\Servicos\ServicoUsuario;
use App\Modulos\Usuarios\Validadores\SolicitacaoCadastro;
use App\Modulos\Usuarios\Validadores\SolicitacaoEntrada;
use App\Modulos\Usuarios\Validadores\SolicitacaoLista;
use App\Modulos\Usuarios\Validadores\SolicitacaoRenovacao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ControladorUsuario extends Controller
{
    public function cadastrar(SolicitacaoCadastro $requisicao, ServicoUsuario $servico): JsonResponse
    {
        $dados = $requisicao->validated();

        return response()->json($servico->cadastrar(trim($dados['nome']), mb_strtolower($dados['email']), $dados['senha']), 201);
    }

    public function entrar(SolicitacaoEntrada $requisicao, ServicoUsuario $servico): JsonResponse
    {
        $dados = $requisicao->validated();

        return response()->json($servico->autenticar(mb_strtolower($dados['email']), $dados['senha']));
    }

    public function renovar(SolicitacaoRenovacao $requisicao, ServicoUsuario $servico): JsonResponse
    {
        $credenciais = $servico->renovar($requisicao->validated('token'));
        if ($credenciais === null) {
            return response()->json([
                'codigo' => 'AUTENTICACAO_NECESSARIA',
                'mensagem' => 'Autenticação necessária.',
                'rastreamento' => (string) Str::uuid(),
            ], 401);
        }

        return response()->json($credenciais);
    }

    public function atual(Request $requisicao): JsonResponse
    {
        $usuario = $requisicao->attributes->get('usuario_atual');

        return response()->json(['id' => $usuario->uuid, 'nome' => $usuario->nome, 'email' => $usuario->email, 'papel' => $usuario->papel]);
    }

    public function sair(Request $requisicao, RepositorioUsuario $repositorio): JsonResponse
    {
        $repositorio->invalidarTokens($requisicao->attributes->get('usuario_atual'));

        return response()->json(['mensagem' => 'Sessão encerrada.']);
    }

    public function listar(SolicitacaoLista $requisicao, RepositorioUsuario $repositorio): JsonResponse
    {
        abort_unless($requisicao->attributes->get('usuario_atual')->papel === 'administrador', 403);
        $limite = (int) ($requisicao->validated('limite') ?? 20);
        $usuarios = $repositorio->listarApos($requisicao->validated('apos'), $limite);
        $temProxima = $usuarios->count() > $limite;
        $pagina = $usuarios->take($limite)->values();

        return response()->json([
            'dados' => $pagina->map(fn ($usuario) => [
                'id' => $usuario->uuid, 'nome' => $usuario->nome, 'email' => $usuario->email,
                'papel' => $usuario->papel, 'criado_em' => $usuario->criado_em,
            ]),
            'proximo_cursor' => $temProxima ? $pagina->last()->uuid : null,
        ]);
    }
}
