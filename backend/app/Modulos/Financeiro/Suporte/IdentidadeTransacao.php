<?php

namespace App\Modulos\Financeiro\Suporte;

use Illuminate\Support\Str;

final class IdentidadeTransacao
{
    public static function descricao(string $valor): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower(Str::ascii($valor))) ?? '');
    }

    public static function fingerprint(int $usuario, int $conta, string $data, string $valor, string $moeda, ?string $documento, string $descricao): string
    {
        return hash('sha256', implode('|', [
            $usuario, $conta, $data, EntradaFinanceira::moeda($valor),
            $moeda, trim((string) $documento), self::descricao($descricao),
        ]));
    }
}
