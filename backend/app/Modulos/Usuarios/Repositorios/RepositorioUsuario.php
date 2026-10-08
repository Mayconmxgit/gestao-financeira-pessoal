<?php

namespace App\Modulos\Usuarios\Repositorios;

use App\Modulos\Usuarios\Modelos\Usuario;
use Illuminate\Database\Eloquent\Collection;

final class RepositorioUsuario
{
    public function buscarPorEmail(string $email): ?Usuario
    {
        return Usuario::query()->where('email', $email)->first(['id', 'uuid', 'nome', 'email', 'senha', 'papel', 'versao_token']);
    }

    public function buscarPorId(int $identificador): ?Usuario
    {
        return Usuario::query()->find($identificador, ['id', 'uuid', 'nome', 'email', 'papel', 'versao_token']);
    }

    public function criar(string $nome, string $email, string $senha): Usuario
    {
        return Usuario::query()->create(compact('nome', 'email', 'senha'));
    }

    public function listarApos(?string $ultimoUuid, int $limite): Collection
    {
        $ultimoIdentificador = $ultimoUuid === null ? null : Usuario::query()->where('uuid', $ultimoUuid)->value('id');
        abort_if($ultimoUuid !== null && $ultimoIdentificador === null, 422);

        // Cursor exclusivo mantém a consulta indexável em listas grandes.
        return Usuario::query()
            ->when($ultimoIdentificador !== null, fn ($consulta) => $consulta->where('id', '>', $ultimoIdentificador))
            ->orderBy('id')
            ->limit($limite + 1)
            ->get(['id', 'uuid', 'nome', 'email', 'papel', 'criado_em']);
    }

    public function invalidarTokens(Usuario $usuario): void
    {
        Usuario::query()->whereKey($usuario->id)->increment('versao_token');
    }
}
