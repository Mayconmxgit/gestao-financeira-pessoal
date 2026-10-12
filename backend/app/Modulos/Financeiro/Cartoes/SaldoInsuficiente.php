<?php

namespace App\Modulos\Financeiro\Cartoes;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class SaldoInsuficiente extends RuntimeException
{
    public function __construct(
        public readonly string $conta,
        public readonly string $saldoDisponivel,
        public readonly string $valorFatura,
        public readonly string $valorFaltante,
    ) {
        parent::__construct('Saldo insuficiente para pagar a fatura.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'mensagem' => $this->getMessage(),
            'codigo' => 'saldo_insuficiente',
            'conta' => $this->conta,
            'saldo_disponivel' => $this->saldoDisponivel,
            'valor_fatura' => $this->valorFatura,
            'valor_faltante' => $this->valorFaltante,
        ], 409);
    }
}
