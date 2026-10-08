<?php

namespace App\Modulos\Usuarios\Validadores;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class SolicitacaoCadastro extends FormRequest
{
    use MensagensValidacao;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'senha' => RegraSenha::regras(),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validador): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules()))) {
                $validador->errors()->add('campos', 'A requisição contém campos não permitidos.');
            }
        }];
    }
}
