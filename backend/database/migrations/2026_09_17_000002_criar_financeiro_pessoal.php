<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contas', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->foreignId('usuario_id')->constrained('usuarios');
            $tabela->string('nome', 120);
            $tabela->string('tipo', 30);
            $tabela->char('moeda', 3);
            $tabela->decimal('saldo_inicial', 18, 2);
            $tabela->date('data_saldo');
            $tabela->string('instituicao', 120)->nullable();
            $tabela->string('situacao', 20)->default('ativa');
            $tabela->timestampsTz();
            $tabela->index(['usuario_id', 'situacao']);
        });

        Schema::create('categorias', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->foreignId('usuario_id')->constrained('usuarios');
            $tabela->string('nome', 80);
            $tabela->string('tipo', 20);
            $tabela->timestampsTz();
            $tabela->unique(['usuario_id', 'tipo', 'nome']);
        });

        Schema::create('lotes_importacao', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->foreignId('usuario_id')->constrained('usuarios');
            $tabela->foreignId('conta_id')->constrained('contas');
            $tabela->string('instituicao', 120)->nullable();
            $tabela->string('formato', 10);
            $tabela->string('nome_arquivo', 180);
            $tabela->char('hash_arquivo', 64);
            $tabela->string('adaptador', 50);
            $tabela->unsignedSmallInteger('versao_adaptador');
            $tabela->string('situacao', 20);
            $tabela->date('periodo_inicio')->nullable();
            $tabela->date('periodo_fim')->nullable();
            $tabela->unsignedInteger('linhas_lidas')->default(0);
            $tabela->unsignedInteger('linhas_importadas')->default(0);
            $tabela->unsignedInteger('linhas_ignoradas')->default(0);
            $tabela->unsignedInteger('linhas_invalidas')->default(0);
            $tabela->timestampTz('confirmado_em')->nullable();
            $tabela->timestampTz('revertido_em')->nullable();
            $tabela->timestampsTz();
            $tabela->index(['usuario_id', 'conta_id', 'hash_arquivo']);
        });

        Schema::create('linhas_importacao', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->foreignId('lote_id')->constrained('lotes_importacao');
            $tabela->unsignedInteger('numero');
            $tabela->date('data_efetiva')->nullable();
            $tabela->decimal('valor', 18, 2)->nullable();
            $tabela->string('descricao_original', 500)->nullable();
            $tabela->string('descricao_normalizada', 500)->nullable();
            $tabela->string('identificador_bancario', 150)->nullable();
            $tabela->string('documento', 120)->nullable();
            $tabela->char('fingerprint', 64)->nullable();
            $tabela->string('erro', 200)->nullable();
            $tabela->unique(['lote_id', 'numero']);
        });

        Schema::create('transacoes', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->foreignId('usuario_id')->constrained('usuarios');
            $tabela->foreignId('conta_id')->constrained('contas');
            $tabela->foreignId('lote_id')->nullable()->constrained('lotes_importacao');
            $tabela->foreignId('categoria_id')->nullable()->constrained('categorias');
            $tabela->foreignId('transacao_relacionada_id')->nullable()->constrained('transacoes');
            $tabela->date('data_efetiva');
            $tabela->decimal('valor', 18, 2);
            $tabela->char('moeda', 3);
            $tabela->string('descricao_original', 500);
            $tabela->string('descricao_normalizada', 500);
            $tabela->string('identificador_bancario', 150)->nullable();
            $tabela->string('documento', 120)->nullable();
            $tabela->char('fingerprint', 64);
            $tabela->string('natureza', 30);
            $tabela->string('situacao', 20);
            $tabela->string('origem', 20);
            $tabela->timestampsTz();
            $tabela->unique(['conta_id', 'identificador_bancario']);
            $tabela->index(['usuario_id', 'conta_id', 'data_efetiva']);
            $tabela->index(['conta_id', 'fingerprint']);
        });

        Schema::create('decisoes_conciliacao', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->foreignId('usuario_id')->constrained('usuarios');
            $tabela->foreignId('linha_id')->constrained('linhas_importacao');
            $tabela->foreignId('transacao_candidata_id')->nullable()->constrained('transacoes');
            $tabela->foreignId('transacao_criada_id')->nullable()->constrained('transacoes');
            $tabela->string('decisao', 30);
            $tabela->string('motivo', 300)->nullable();
            $tabela->timestampTz('criado_em')->useCurrent();
        });

        Schema::create('auditoria_financeira', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->foreignId('usuario_id')->constrained('usuarios');
            $tabela->string('acao', 60);
            $tabela->string('entidade', 60);
            $tabela->unsignedBigInteger('entidade_id');
            $tabela->json('detalhes')->nullable();
            $tabela->timestampTz('criado_em')->useCurrent();
            $tabela->index(['usuario_id', 'criado_em']);
        });

        Schema::create('consentimentos_ia', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->foreignId('usuario_id')->constrained('usuarios');
            $tabela->string('versao', 30);
            $tabela->string('provedor', 60);
            $tabela->boolean('ativo');
            $tabela->timestampTz('criado_em')->useCurrent();
            $tabela->index(['usuario_id', 'criado_em']);
        });

        Schema::create('uso_ia', function (Blueprint $tabela): void {
            $tabela->bigIncrements('id');
            $tabela->foreignId('usuario_id')->constrained('usuarios');
            $tabela->string('provedor', 60);
            $tabela->string('modelo', 100)->nullable();
            $tabela->string('resultado', 30);
            $tabela->unsignedInteger('latencia_ms')->nullable();
            $tabela->timestampTz('criado_em')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['uso_ia', 'consentimentos_ia', 'auditoria_financeira', 'decisoes_conciliacao', 'transacoes', 'linhas_importacao', 'lotes_importacao', 'categorias', 'contas'] as $tabela) {
            Schema::dropIfExists($tabela);
        }
    }
};
