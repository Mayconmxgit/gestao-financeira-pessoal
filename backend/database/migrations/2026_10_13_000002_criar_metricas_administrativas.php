<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventos_ciclo_assinatura', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->uuid('uuid')->unique();
            $tabela->foreignId('assinatura_id')->constrained('assinaturas')->restrictOnDelete();
            $tabela->string('situacao_anterior', 30)->nullable();
            $tabela->string('situacao_nova', 30);
            $tabela->string('origem', 40);
            $tabela->timestampTz('ocorrido_em');
            $tabela->index(['ocorrido_em', 'situacao_nova'], 'ciclo_data_situacao_indice');
            $tabela->index(['assinatura_id', 'ocorrido_em'], 'ciclo_assinatura_data_indice');
        });

        Schema::create('metricas_admin_diarias', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->date('data')->unique();
            $tabela->unsignedBigInteger('usuarios_total')->default(0);
            $tabela->unsignedBigInteger('usuarios_ativos_30d')->default(0);
            $tabela->unsignedBigInteger('assinaturas_ativas')->default(0);
            $tabela->unsignedBigInteger('assinaturas_pagas')->default(0);
            $tabela->unsignedBigInteger('assinaturas_gratuitas')->default(0);
            $tabela->unsignedBigInteger('assinaturas_cortesias')->default(0);
            $tabela->unsignedBigInteger('assinaturas_pendentes')->default(0);
            $tabela->unsignedBigInteger('assinaturas_inadimplentes')->default(0);
            $tabela->unsignedBigInteger('falhas_webhook')->default(0);
            $tabela->decimal('mrr_contratado', 14, 2)->default(0);
            $tabela->timestampsTz();
        });

        Schema::create('metricas_admin_planos_diarias', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->date('data');
            $tabela->foreignId('plano_id')->constrained('planos_assinatura')->restrictOnDelete();
            $tabela->unsignedBigInteger('assinaturas_ativas')->default(0);
            $tabela->decimal('mrr_contratado', 14, 2)->default(0);
            $tabela->timestampsTz();
            $tabela->unique(['data', 'plano_id'], 'metricas_plano_data_unico');
            $tabela->index(['plano_id', 'data'], 'metricas_plano_data_indice');
        });

        Schema::table('usuarios', function (Blueprint $tabela): void {
            $tabela->index(['papel', 'criado_em'], 'usuarios_papel_criacao_indice');
        });
        Schema::table('assinaturas', function (Blueprint $tabela): void {
            $tabela->foreignId('assinatura_substituida_id')->nullable()->constrained('assinaturas')->restrictOnDelete();
            $tabela->index(['situacao', 'created_at'], 'assinaturas_situacao_criacao_indice');
            $tabela->index(['plano_id', 'situacao'], 'assinaturas_plano_situacao_indice');
            $tabela->index('cancelada_em', 'assinaturas_cancelamento_indice');
        });
    }

    public function down(): void
    {
        Schema::table('assinaturas', function (Blueprint $tabela): void {
            $tabela->dropIndex('assinaturas_situacao_criacao_indice');
            $tabela->dropIndex('assinaturas_plano_situacao_indice');
            $tabela->dropIndex('assinaturas_cancelamento_indice');
            $tabela->dropConstrainedForeignId('assinatura_substituida_id');
        });
        Schema::table('usuarios', function (Blueprint $tabela): void {
            $tabela->dropIndex('usuarios_papel_criacao_indice');
        });
        Schema::dropIfExists('metricas_admin_planos_diarias');
        Schema::dropIfExists('metricas_admin_diarias');
        Schema::dropIfExists('eventos_ciclo_assinatura');
    }
};
