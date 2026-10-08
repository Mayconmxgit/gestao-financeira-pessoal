<?php

return [
    'acesso_obrigatorio' => env('ASSINATURAS_ACESSO_OBRIGATORIO', true),
    'mercado_pago' => [
        'ativo' => env('MERCADO_PAGO_ASSINATURAS_ATIVO', false),
        'url' => 'https://api.mercadopago.com',
        'token' => env('MERCADO_PAGO_ACCESS_TOKEN'),
        'segredo_webhook' => env('MERCADO_PAGO_WEBHOOK_SECRET'),
        'url_retorno' => env('MERCADO_PAGO_BACK_URL', 'http://localhost:3000/assinatura'),
        'tolerancia_webhook_segundos' => 300,
    ],
];
