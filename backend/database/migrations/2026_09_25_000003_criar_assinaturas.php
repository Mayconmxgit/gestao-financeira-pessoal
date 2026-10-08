<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planos_assinatura', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->uuid('uuid')->unique();
            $tabela->string('nome', 100);
            $tabela->string('descricao', 500)->nullable();
            $tabela->decimal('valor', 12, 2);
            $tabela->char('moeda', 3)->default('BRL');
            $tabela->string('periodicidade', 10);
            $tabela->unsignedSmallInteger('intervalo')->default(1);
            $tabela->json('recursos');
            $tabela->boolean('ativo')->default(true);
            $tabela->timestampsTz();
            $tabela->index(['ativo', 'valor']);
        });

        Schema::create('assinaturas', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->uuid('uuid')->unique();
            $tabela->foreignId('usuario_id')->constrained('usuarios');
            $tabela->foreignId('plano_id')->constrained('planos_assinatura');
            $tabela->string('provedor', 30)->default('mercado_pago');
            $tabela->string('identificador_externo', 120)->nullable()->unique();
            $tabela->uuid('referencia_externa')->unique();
            $tabela->string('situacao', 30)->default('pendente');
            $tabela->decimal('valor_contratado', 12, 2);
            $tabela->char('moeda', 3);
            $tabela->string('periodicidade', 10);
            $tabela->unsignedSmallInteger('intervalo');
            $tabela->text('url_checkout')->nullable();
            $tabela->timestampTz('proxima_cobranca_em')->nullable();
            $tabela->timestampTz('periodo_acesso_fim')->nullable();
            $tabela->timestampTz('cancelada_em')->nullable();
            $tabela->timestampsTz();
            $tabela->index(['usuario_id', 'situacao'], 'assinaturas_usuario_situacao_indice');
            $tabela->index(['situacao', 'proxima_cobranca_em'], 'assinaturas_situacao_cobranca_indice');
        });

        Schema::create('cobrancas_assinatura', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->uuid('uuid')->unique();
            $tabela->foreignId('assinatura_id')->constrained('assinaturas');
            $tabela->string('identificador_externo', 120)->nullable()->unique();
            $tabela->string('situacao', 30);
            $tabela->decimal('valor', 12, 2);
            $tabela->timestampTz('vencimento_em')->nullable();
            $tabela->timestampTz('paga_em')->nullable();
            $tabela->timestampsTz();
            $tabela->index(['assinatura_id', 'created_at']);
        });

        Schema::create('eventos_webhook_assinatura', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->uuid('uuid')->unique();
            $tabela->string('provedor', 30);
            $tabela->string('requisicao_id', 120);
            $tabela->string('recurso_id', 120);
            $tabela->string('tipo', 80);
            $tabela->string('acao', 100)->nullable();
            $tabela->string('situacao', 20)->default('recebido');
            $tabela->string('codigo_erro', 80)->nullable();
            $tabela->timestampTz('processado_em')->nullable();
            $tabela->timestampsTz();
            $tabela->unique(['provedor', 'requisicao_id'], 'webhook_provedor_requisicao_unico');
            $tabela->index(['situacao', 'created_at']);
        });

        Schema::create('auditoria_assinaturas', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->foreignId('administrador_id')->constrained('usuarios');
            $tabela->string('acao', 80);
            $tabela->string('entidade', 50);
            $tabela->uuid('entidade_uuid');
            $tabela->json('detalhes')->nullable();
            $tabela->timestampTz('criado_em')->useCurrent();
            $tabela->index(['entidade', 'entidade_uuid', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria_assinaturas');
        Schema::dropIfExists('eventos_webhook_assinatura');
        Schema::dropIfExists('cobrancas_assinatura');
        Schema::dropIfExists('assinaturas');
        Schema::dropIfExists('planos_assinatura');
    }
};
