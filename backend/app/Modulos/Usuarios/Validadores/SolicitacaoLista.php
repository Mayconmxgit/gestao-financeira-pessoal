<?php

namespace App\Modulos\Usuarios\Validadores;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class SolicitacaoLista extends FormRequest
{
    use MensagensValidacao;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'apos' => ['sometimes', 'uuid'],
            'limite' => ['sometimes', 'integer', 'between:1,50'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validador): void {
            if (array_diff(array_keys($this->query()), array_keys($this->rules()))) {
                $validador->errors()->add('campos', 'A consulta contém parâmetros não permitidos.');
            }
        }];
    }
}
