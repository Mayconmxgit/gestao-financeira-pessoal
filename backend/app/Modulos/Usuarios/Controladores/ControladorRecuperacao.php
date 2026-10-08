<?php

namespace App\Modulos\Usuarios\Controladores;

use App\Http\Controllers\Controller;
use App\Mail\RecuperacaoAcesso;
use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use App\Modulos\Usuarios\Validadores\RegraSenha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

final class ControladorRecuperacao extends Controller
{
    public function solicitar(Request $requisicao): JsonResponse
    {
        $dados = Entrada::validar($requisicao, ['email' => ['required', 'email:rfc', 'max:254']]);
        $email = mb_strtolower($dados['email']);
        $resposta = ['mensagem' => 'Se o endereço estiver cadastrado, enviaremos as instruções de recuperação.'];
        if (! app()->environment('testing') && in_array(config('mail.default'), ['log', 'array'], true)) {
            return response()->json(['mensagem' => 'Recuperação indisponível no momento.'], 503);
        }
        $usuario = DB::table('usuarios')->where('email', $email)->first();
        if ($usuario === null) {
            return response()->json($resposta);
        }
        $token = bin2hex(random_bytes(32));
        $url = rtrim((string) config('cors.allowed_origins.0'), '/').'/redefinir-senha?'.http_build_query([
            'email' => $email, 'token' => $token,
        ]);
        DB::table('recuperacoes_acesso')->upsert([[
            'email' => $email, 'token_hash' => hash('sha256', $token),
            'expira_em' => now()->addMinutes(30), 'criado_em' => now(),
        ]], ['email'], ['token_hash', 'expira_em', 'criado_em']);
        try {
            Mail::to($email)->send(new RecuperacaoAcesso($url));
        } catch (\Throwable $excecao) {
            DB::table('recuperacoes_acesso')->where('email', $email)->delete();
            Log::warning('Falha ao enviar recuperação de acesso', ['tipo' => get_class($excecao)]);

            return response()->json($resposta);
        }

        return response()->json($resposta);
    }

    public function redefinir(Request $requisicao): JsonResponse
    {
        $dados = Entrada::validar($requisicao, [
            'email' => ['required', 'email:rfc', 'max:254'],
            'token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'senha' => RegraSenha::regras(),
        ]);
        $email = mb_strtolower($dados['email']);
        DB::transaction(function () use ($email, $dados): void {
            $registro = DB::table('recuperacoes_acesso')->where('email', $email)->lockForUpdate()->first();
            if ($registro === null || now()->greaterThan($registro->expira_em) || ! hash_equals($registro->token_hash, hash('sha256', $dados['token']))) {
                throw ValidationException::withMessages(['token' => 'Token inválido ou expirado.']);
            }
            DB::table('usuarios')->where('email', $email)->update([
                'senha' => Hash::driver('argon2id')->make($dados['senha']),
                'versao_token' => DB::raw('versao_token + 1'), 'atualizado_em' => now(),
            ]);
            DB::table('recuperacoes_acesso')->where('email', $email)->delete();
        });

        return response()->json(['mensagem' => 'Senha redefinida. Entre novamente.']);
    }
}
