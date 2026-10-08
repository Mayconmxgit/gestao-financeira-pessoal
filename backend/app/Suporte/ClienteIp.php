<?php

namespace App\Suporte;

use Illuminate\Http\Request;

final class ClienteIp
{
    private const CABECALHO_INTERNO = 'X-Vida-Cliente-IP';

    public static function obter(Request $requisicao): string
    {
        $origem = $requisicao->ip() ?? '';
        $encaminhado = trim((string) $requisicao->header(self::CABECALHO_INTERNO, ''));

        if (self::ehLoopback($origem) && filter_var($encaminhado, FILTER_VALIDATE_IP) !== false) {
            return $encaminhado;
        }

        return $origem;
    }

    private static function ehLoopback(string $ip): bool
    {
        return $ip === '127.0.0.1' || $ip === '::1';
    }
}
