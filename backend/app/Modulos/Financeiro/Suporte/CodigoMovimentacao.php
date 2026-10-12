<?php

namespace App\Modulos\Financeiro\Suporte;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CodigoMovimentacao
{
    public static function novo(int $usuario): string
    {
        for ($tentativa = 0; $tentativa < 20; $tentativa++) {
            $codigo = (string) random_int(100000000, 999999999);
            $existe = DB::table('transacoes')->where('usuario_id', $usuario)->where('codigo', $codigo)->exists();
            if (! $existe) {
                return $codigo;
            }
        }

        throw new RuntimeException('Não foi possível gerar um código único para a movimentação.');
    }

    public static function normalizar(string $codigo): ?string
    {
        $normalizado = ltrim(trim($codigo), '#');

        return preg_match('/^[1-9][0-9]{8}$/', $normalizado) === 1 ? $normalizado : null;
    }

    public static function resolver(int $usuario, string $codigo): ?int
    {
        $normalizado = self::normalizar($codigo);
        if ($normalizado === null) {
            return null;
        }

        $id = DB::table('transacoes')->where('usuario_id', $usuario)->where('codigo', $normalizado)->value('id');

        return $id === null ? null : (int) $id;
    }
}
