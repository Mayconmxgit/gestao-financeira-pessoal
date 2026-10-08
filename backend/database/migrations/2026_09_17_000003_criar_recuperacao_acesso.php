<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recuperacoes_acesso', function (Blueprint $tabela): void {
            $tabela->string('email', 254)->primary();
            $tabela->char('token_hash', 64);
            $tabela->timestampTz('expira_em');
            $tabela->timestampTz('criado_em')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recuperacoes_acesso');
    }
};
