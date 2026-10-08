<?php

return [
    'segredo' => env('JWT_SEGREDO_BASE64'),
    'emissor' => env('JWT_EMISSOR', env('APP_URL', 'http://localhost:8000')),
    'publico' => env('JWT_PUBLICO', 'vida-financeira-api'),
    'duracao_minutos' => (int) env('JWT_DURACAO_MINUTOS', 15),
    'renovacao_dias' => (int) env('JWT_RENOVACAO_DIAS', 7),
];
