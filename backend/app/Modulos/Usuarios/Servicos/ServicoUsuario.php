<?php

namespace App\Modulos\Usuarios\Servicos;

use App\Modulos\Usuarios\Modelos\Usuario;
use App\Modulos\Usuarios\Repositorios\RepositorioUsuario;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class ServicoUsuario
{
    public function __construct(private RepositorioUsuario $repositorio, private ServicoToken $tokens) {}

    public function cadastrar(string $nome, string $email, string $senha): array
    {
        $this->tokens->validarConfiguracao();
        if ($this->repositorio->buscarPorEmail($email) !== null) {
            throw ValidationException::withMessages(['email' => 'Não foi possível concluir o cadastro.']);
        }

        try {
            $usuario = $this->repositorio->criar($nome, $email, Hash::driver('argon2id')->make($senha));
        } catch (UniqueConstraintViolationException $excecao) {
            throw ValidationException::withMessages(['email' => 'Não foi possível concluir o cadastro.']);
        }

        return $this->respostaAutenticacao($usuario);
    }

    public function autenticar(string $email, string $senha): array
    {
        $usuario = $this->repositorio->buscarPorEmail($email);
        if ($usuario === null) {
            $this->compararSenhaFicticia($senha);
            throw ValidationException::withMessages(['credenciais' => 'Credenciais inválidas.']);
        }
        if (! Hash::driver('argon2id')->check($senha, $usuario->senha)) {
            throw ValidationException::withMessages(['credenciais' => 'Credenciais inválidas.']);
        }

        return $this->respostaAutenticacao($usuario);
    }

    public function renovar(string $token): ?array
    {
        $dados = $this->tokens->verificarRenovacao($token);
        $usuario = $dados === null ? null : $this->repositorio->buscarPorId((int) $dados->sub);
        if ($usuario === null || $usuario->versao_token !== ($dados->versao ?? null)) {
            return null;
        }

        return $this->credenciais($usuario);
    }

    private function compararSenhaFicticia(string $senha): void
    {
        static $hashFicticio = null;
        $hashFicticio ??= Hash::driver('argon2id')->make(bin2hex(random_bytes(16)));
        Hash::driver('argon2id')->check($senha, $hashFicticio);
    }

    private function respostaAutenticacao(Usuario $usuario): array
    {
        return [
            'usuario' => ['id' => $usuario->uuid, 'nome' => $usuario->nome, 'email' => $usuario->email, 'papel' => $usuario->papel],
            ...$this->credenciais($usuario),
        ];
    }

    private function credenciais(Usuario $usuario): array
    {
        return [
            'token' => $this->tokens->emitir($usuario),
            'tipo' => 'Bearer',
            'expira_em_segundos' => 60 * config('autenticacao.duracao_minutos'),
            'token_renovacao' => $this->tokens->emitirRenovacao($usuario),
            'renovacao_expira_em_segundos' => 86_400 * config('autenticacao.renovacao_dias'),
        ];
    }
}
