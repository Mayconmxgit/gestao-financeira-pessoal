<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->string('nome', 120);
            $tabela->string('email', 254)->unique();
            $tabela->string('senha', 255);
            $tabela->string('papel', 20)->default('usuario');
            $tabela->unsignedInteger('versao_token')->default(1);
            $tabela->timestampTz('criado_em')->useCurrent();
            $tabela->timestampTz('atualizado_em')->useCurrent();
            $tabela->index(['papel', 'id'], 'usuarios_papel_id_indice');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
