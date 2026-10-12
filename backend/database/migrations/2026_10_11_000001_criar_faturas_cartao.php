<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contas', function (Blueprint $tabela): void {
            $tabela->foreignId('conta_pagamento_id')->nullable()->after('instituicao')->constrained('contas');
            $tabela->decimal('limite_credito', 18, 2)->nullable()->after('conta_pagamento_id');
            $tabela->unsignedTinyInteger('dia_fechamento')->nullable()->after('limite_credito');
            $tabela->unsignedTinyInteger('dia_vencimento')->nullable()->after('dia_fechamento');
        });

        Schema::create('faturas_cartao', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->uuid('uuid')->unique();
            $tabela->foreignId('usuario_id')->constrained('usuarios');
            $tabela->foreignId('cartao_id')->constrained('contas');
            $tabela->foreignId('conta_pagamento_id')->constrained('contas');
            $tabela->date('inicio_periodo');
            $tabela->date('fim_periodo');
            $tabela->date('fechamento');
            $tabela->date('vencimento');
            $tabela->decimal('valor_total', 18, 2)->default(0);
            $tabela->decimal('credito_anterior', 18, 2)->default(0);
            $tabela->decimal('credito_proximo', 18, 2)->default(0);
            $tabela->string('situacao', 20)->default('aberta');
            $tabela->foreignId('transacao_pagamento_id')->nullable()->constrained('transacoes');
            $tabela->foreignId('transacao_cartao_id')->nullable()->constrained('transacoes');
            $tabela->timestampTz('paga_em')->nullable();
            $tabela->timestampsTz();
            $tabela->unique(['cartao_id', 'fechamento'], 'faturas_cartao_ciclo_unico');
            $tabela->index(['usuario_id', 'situacao', 'vencimento'], 'faturas_cartao_usuario_situacao_indice');
        });

        Schema::table('transacoes', function (Blueprint $tabela): void {
            $tabela->foreignId('fatura_cartao_id')->nullable()->after('conta_id')->constrained('faturas_cartao');
            $tabela->index(['conta_id', 'fatura_cartao_id'], 'transacoes_conta_fatura_indice');
        });

        Schema::table('pagamentos_recorrentes', function (Blueprint $tabela): void {
            $tabela->string('falha_automatica', 80)->nullable()->after('cobranca_automatica');
            $tabela->timestampTz('falha_automatica_em')->nullable()->after('falha_automatica');
        });
    }

    public function down(): void
    {
        Schema::table('pagamentos_recorrentes', function (Blueprint $tabela): void {
            $tabela->dropColumn(['falha_automatica', 'falha_automatica_em']);
        });
        Schema::table('transacoes', function (Blueprint $tabela): void {
            $tabela->dropForeign(['fatura_cartao_id']);
            $tabela->dropIndex('transacoes_conta_fatura_indice');
            $tabela->dropColumn('fatura_cartao_id');
        });
        Schema::dropIfExists('faturas_cartao');
        Schema::table('contas', function (Blueprint $tabela): void {
            $tabela->dropForeign(['conta_pagamento_id']);
            $tabela->dropColumn(['conta_pagamento_id', 'limite_credito', 'dia_fechamento', 'dia_vencimento']);
        });
    }
};
