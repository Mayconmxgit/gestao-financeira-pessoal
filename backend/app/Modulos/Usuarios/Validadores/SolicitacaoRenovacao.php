<?php

namespace App\Modulos\Usuarios\Validadores;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class SolicitacaoRenovacao extends FormRequest
{
    use MensagensValidacao;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['token' => ['required', 'string', 'max:4096']];
    }

    public function after(): array
    {
        return [function (Validator $validador): void {
            if (array_diff(array_keys($this->all()), ['token'])) {
                $validador->errors()->add('campos', 'A requisição contém campos não permitidos.');
            }
        }];
    }
}
