<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aportes_metas', function (Blueprint $tabela): void {
            $tabela->foreignId('conta_id')->nullable()->after('meta_id')->constrained('contas');
            $tabela->foreignId('transacao_id')->nullable()->unique()->after('conta_id')->constrained('transacoes');
        });
    }

    public function down(): void
    {
        Schema::table('aportes_metas', function (Blueprint $tabela): void {
            $tabela->dropConstrainedForeignId('transacao_id');
            $tabela->dropConstrainedForeignId('conta_id');
        });
    }
};
