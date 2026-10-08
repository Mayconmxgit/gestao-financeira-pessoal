<?php

namespace App\Modulos\Usuarios\Servicos;

use App\Modulos\Usuarios\Modelos\Usuario;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;

final class ServicoToken
{
    private const TIPO_ACESSO = 'acesso';

    private const TIPO_RENOVACAO = 'renovacao';

    public function validarConfiguracao(): void
    {
        $this->chave();
    }

    private function chave(): string
    {
        $chave = base64_decode((string) config('autenticacao.segredo'), true);
        if ($chave === false || strlen($chave) < 32) {
            throw new RuntimeException('JWT_SEGREDO_BASE64 precisa conter pelo menos 32 bytes aleatórios.');
        }

        return $chave;
    }

    public function emitir(Usuario $usuario): string
    {
        return $this->emitirComDuracao(
            $usuario,
            self::TIPO_ACESSO,
            60 * (int) config('autenticacao.duracao_minutos'),
        );
    }

    public function emitirRenovacao(Usuario $usuario): string
    {
        return $this->emitirComDuracao(
            $usuario,
            self::TIPO_RENOVACAO,
            86_400 * (int) config('autenticacao.renovacao_dias'),
        );
    }

    private function emitirComDuracao(Usuario $usuario, string $tipo, int $duracaoSegundos): string
    {
        $agora = time();

        return JWT::encode([
            'iss' => config('autenticacao.emissor'),
            'aud' => config('autenticacao.publico'),
            'sub' => (string) $usuario->id,
            'iat' => $agora,
            'nbf' => $agora,
            'exp' => $agora + $duracaoSegundos,
            'versao' => $usuario->versao_token,
            'tipo' => $tipo,
        ], $this->chave(), 'HS256');
    }

    public function verificar(string $token): ?object
    {
        return $this->verificarTipo($token, self::TIPO_ACESSO);
    }

    public function verificarRenovacao(string $token): ?object
    {
        return $this->verificarTipo($token, self::TIPO_RENOVACAO);
    }

    private function verificarTipo(string $token, string $tipoEsperado): ?object
    {
        $chave = $this->chave();
        try {
            $dados = JWT::decode($token, new Key($chave, 'HS256'));
        } catch (\Throwable $excecao) {
            return null;
        }

        if (! isset($dados->iss, $dados->aud) || $dados->iss !== config('autenticacao.emissor') || $dados->aud !== config('autenticacao.publico')) {
            return null;
        }
        if (! isset($dados->sub, $dados->versao) || ! ctype_digit((string) $dados->sub) || ! is_int($dados->versao)) {
            return null;
        }
        $tipo = $dados->tipo ?? self::TIPO_ACESSO;
        if (! is_string($tipo) || $tipo !== $tipoEsperado) {
            return null;
        }

        return $dados;
    }
}
