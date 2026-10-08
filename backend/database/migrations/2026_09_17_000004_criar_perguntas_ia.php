<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perguntas_ia', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->foreignId('usuario_id')->constrained('usuarios');
            $tabela->date('periodo_inicio');
            $tabela->date('periodo_fim');
            $tabela->text('pergunta_cifrada');
            $tabela->text('resposta_cifrada')->nullable();
            $tabela->json('dados_usados')->nullable();
            $tabela->string('situacao', 20);
            $tabela->string('codigo_erro', 40)->nullable();
            $tabela->timestampsTz();
            $tabela->index(['usuario_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perguntas_ia');
    }
};
