<?php

namespace App\Modulos\Usuarios\Validadores;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class RegraSenha implements ValidationRule
{
    public const MENSAGEM = 'A senha deve ter no mínimo 8 caracteres, com letra maiúscula, letra minúscula e caractere especial.';

    public static function regras(): array
    {
        return ['required', 'string', 'max:128', new self];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $atendePolitica = mb_strlen($value) >= 8
            && preg_match('/\p{Lu}/u', $value) === 1
            && preg_match('/\p{Ll}/u', $value) === 1
            && preg_match('/[\p{P}\p{S}]/u', $value) === 1;

        if (! $atendePolitica) {
            $fail(self::MENSAGEM);
        }
    }
}
