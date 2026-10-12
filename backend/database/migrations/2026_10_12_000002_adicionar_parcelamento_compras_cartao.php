<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transacoes', function (Blueprint $tabela): void {
            $tabela->uuid('compra_parcelada_id')->nullable()->after('fatura_cartao_id');
            $tabela->unsignedTinyInteger('numero_parcela')->nullable()->after('compra_parcelada_id');
            $tabela->unsignedTinyInteger('total_parcelas')->nullable()->after('numero_parcela');
            $tabela->index(['usuario_id', 'compra_parcelada_id'], 'transacoes_compra_parcelada_indice');
        });
    }

    public function down(): void
    {
        Schema::table('transacoes', function (Blueprint $tabela): void {
            $tabela->dropIndex('transacoes_compra_parcelada_indice');
            $tabela->dropColumn(['compra_parcelada_id', 'numero_parcela', 'total_parcelas']);
        });
    }
};
