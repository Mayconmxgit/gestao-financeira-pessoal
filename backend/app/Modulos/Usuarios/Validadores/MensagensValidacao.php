<?php

namespace App\Modulos\Usuarios\Validadores;

trait MensagensValidacao
{
    public function messages(): array
    {
        return [
            'required' => 'O campo :attribute é obrigatório.',
            'string' => 'O campo :attribute deve ser texto.',
            'email' => 'Informe um e-mail válido.',
            'min' => 'O campo :attribute deve ter pelo menos :min caracteres.',
            'max' => 'O campo :attribute deve ter no máximo :max caracteres.',
            'integer' => 'O campo :attribute deve ser inteiro.',
            'between' => 'O campo :attribute deve estar entre :min e :max.',
        ];
    }
}
