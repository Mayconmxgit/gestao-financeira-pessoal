<?php

namespace App\Modulos\Financeiro\Emprestimos;

use InvalidArgumentException;

final class AritmeticaMonetaria
{
    public const ESCALA = 18;

    public static function centavos(string|int $valor): int
    {
        $normalizado = trim((string) $valor);
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $normalizado)) {
            throw new InvalidArgumentException('Valor monetário inválido.');
        }

        $negativo = str_starts_with($normalizado, '-');
        $semSinal = ltrim($normalizado, '-');
        [$inteiro, $decimal] = array_pad(explode('.', $semSinal, 2), 2, '');
        $centavos = ((int) $inteiro * 100) + (int) str_pad($decimal, 2, '0');

        return $negativo ? -$centavos : $centavos;
    }

    public static function decimal(int $centavos): string
    {
        $negativo = $centavos < 0;
        $absoluto = abs($centavos);

        return ($negativo ? '-' : '').intdiv($absoluto, 100).'.'.str_pad((string) ($absoluto % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function arredondarCentavos(string $valor): int
    {
        $negativo = str_starts_with($valor, '-');
        $absoluto = ltrim($valor, '-');
        [$inteiro, $decimal] = array_pad(explode('.', $absoluto, 2), 2, '');
        $digitos = str_pad($decimal, 3, '0');
        $centavos = ((int) $inteiro * 100) + (int) substr($digitos, 0, 2);
        if ((int) $digitos[2] >= 5) {
            $centavos++;
        }

        return $negativo ? -$centavos : $centavos;
    }

    public static function taxaDecimal(string|int $percentual): string
    {
        $valor = trim((string) $percentual);
        if (! preg_match('/^\d{1,3}(?:\.\d{1,6})?$/', $valor)) {
            throw new InvalidArgumentException('Taxa percentual inválida.');
        }

        return bcdiv($valor, '100', self::ESCALA);
    }

    public static function raiz(string $valor, int $indice): string
    {
        if ($indice < 1 || bccomp($valor, '0', self::ESCALA) <= 0) {
            throw new InvalidArgumentException('Não foi possível calcular a raiz informada.');
        }
        if ($indice === 1) {
            return $valor;
        }

        $estimativa = '1';
        for ($iteracao = 0; $iteracao < 80; $iteracao++) {
            $potencia = bcpow($estimativa, (string) ($indice - 1), self::ESCALA);
            $quociente = bcdiv($valor, $potencia, self::ESCALA);
            $proxima = bcdiv(
                bcadd(bcmul((string) ($indice - 1), $estimativa, self::ESCALA), $quociente, self::ESCALA),
                (string) $indice,
                self::ESCALA,
            );
            if (bccomp($estimativa, $proxima, 15) === 0) {
                return $proxima;
            }
            $estimativa = $proxima;
        }

        return $estimativa;
    }
}
