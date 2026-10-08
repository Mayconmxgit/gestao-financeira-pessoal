<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagamentos_recorrentes', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->foreignId('usuario_id')->constrained('usuarios');
            $tabela->foreignId('conta_id')->constrained('contas');
            $tabela->foreignId('categoria_id')->nullable()->constrained('categorias');
            $tabela->string('descricao', 500);
            $tabela->decimal('valor', 18, 2);
            $tabela->char('moeda', 3)->default('BRL');
            $tabela->string('periodicidade', 20);
            $tabela->date('primeiro_vencimento');
            $tabela->date('proximo_vencimento')->nullable();
            $tabela->date('data_fim')->nullable();
            $tabela->string('situacao', 20)->default('ativo');
            $tabela->timestampsTz();
            $tabela->index(['usuario_id', 'situacao', 'proximo_vencimento'], 'pagamentos_recorrentes_agenda_indice');
        });

        Schema::create('execucoes_pagamentos_recorrentes', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->foreignId('usuario_id')->constrained('usuarios');
            $tabela->foreignId('pagamento_recorrente_id')->constrained('pagamentos_recorrentes');
            $tabela->foreignId('transacao_id')->unique()->constrained('transacoes');
            $tabela->date('vencimento');
            $tabela->date('pago_em');
            $tabela->decimal('valor', 18, 2);
            $tabela->timestampsTz();
            $tabela->unique(['pagamento_recorrente_id', 'vencimento'], 'execucoes_recorrentes_vencimento_unico');
            $tabela->index(['usuario_id', 'pago_em']);
        });

        Schema::create('metas_financeiras', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->foreignId('usuario_id')->constrained('usuarios');
            $tabela->string('nome', 120);
            $tabela->decimal('valor_objetivo', 18, 2);
            $tabela->char('moeda', 3)->default('BRL');
            $tabela->date('prazo')->nullable();
            $tabela->string('situacao', 20)->default('ativa');
            $tabela->timestampsTz();
            $tabela->index(['usuario_id', 'situacao', 'prazo']);
        });

        Schema::create('aportes_metas', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->foreignId('usuario_id')->constrained('usuarios');
            $tabela->foreignId('meta_id')->constrained('metas_financeiras');
            $tabela->decimal('valor', 18, 2);
            $tabela->date('data');
            $tabela->string('observacao', 300)->nullable();
            $tabela->timestampsTz();
            $tabela->index(['usuario_id', 'meta_id', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aportes_metas');
        Schema::dropIfExists('metas_financeiras');
        Schema::dropIfExists('execucoes_pagamentos_recorrentes');
        Schema::dropIfExists('pagamentos_recorrentes');
    }
};
