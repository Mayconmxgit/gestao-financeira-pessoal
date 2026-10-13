<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transacoes', function (Blueprint $tabela): void {
            $tabela->index(['usuario_id', 'situacao', 'data_efetiva'], 'transacoes_usuario_situacao_data_indice');
        });
    }

    public function down(): void
    {
        Schema::table('transacoes', function (Blueprint $tabela): void {
            $tabela->dropIndex('transacoes_usuario_situacao_data_indice');
        });
    }
};
