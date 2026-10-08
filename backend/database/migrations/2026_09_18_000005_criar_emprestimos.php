<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emprestimos', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->foreignId('usuario_id')->constrained('usuarios');
            $tabela->foreignId('conta_recebimento_id')->constrained('contas');
            $tabela->foreignId('transacao_recebimento_id')->nullable()->constrained('transacoes');
            $tabela->string('credor', 160);
            $tabela->string('descricao', 300)->nullable();
            $tabela->char('moeda', 3);
            $tabela->decimal('valor_principal', 18, 2);
            $tabela->decimal('taxas_totais', 18, 2)->default(0);
            $tabela->decimal('taxa_juros_mensal', 9, 6)->default(0);
            $tabela->string('sistema_amortizacao', 20);
            $tabela->unsignedSmallInteger('quantidade_parcelas');
            $tabela->date('data_recebimento');
            $tabela->date('primeiro_vencimento');
            $tabela->string('situacao', 20)->default('ativo');
            $tabela->timestampTz('quitado_em')->nullable();
            $tabela->timestampsTz();
            $tabela->index(['usuario_id', 'situacao']);
        });

        Schema::create('parcelas_emprestimo', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->foreignId('emprestimo_id')->constrained('emprestimos');
            $tabela->unsignedSmallInteger('numero');
            $tabela->date('vencimento');
            $tabela->decimal('principal', 18, 2);
            $tabela->decimal('juros', 18, 2);
            $tabela->decimal('taxas', 18, 2);
            $tabela->decimal('valor_total', 18, 2);
            $tabela->string('situacao', 30)->default('pendente');
            $tabela->date('data_pagamento')->nullable();
            $tabela->foreignId('conta_pagamento_id')->nullable()->constrained('contas');
            $tabela->foreignId('transacao_principal_id')->nullable()->constrained('transacoes');
            $tabela->foreignId('transacao_encargos_id')->nullable()->constrained('transacoes');
            $tabela->timestampsTz();
            $tabela->unique(['emprestimo_id', 'numero']);
            $tabela->index(['emprestimo_id', 'situacao', 'vencimento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcelas_emprestimo');
        Schema::dropIfExists('emprestimos');
    }
};
