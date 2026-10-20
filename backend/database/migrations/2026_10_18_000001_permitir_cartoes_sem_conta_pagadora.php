<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faturas_cartao', function (Blueprint $tabela): void {
            $tabela->foreignId('conta_pagamento_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('faturas_cartao')->whereNull('conta_pagamento_id')->exists()) {
            throw new RuntimeException('Não é possível restaurar a obrigatoriedade enquanto houver faturas sem conta pagadora.');
        }

        Schema::table('faturas_cartao', function (Blueprint $tabela): void {
            $tabela->foreignId('conta_pagamento_id')->nullable(false)->change();
        });
    }
};
