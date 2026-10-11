<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagamentos_recorrentes', function (Blueprint $tabela): void {
            $tabela->boolean('cobranca_automatica')->default(false)->after('situacao');
            $tabela->index(
                ['cobranca_automatica', 'situacao', 'proximo_vencimento'],
                'pagamentos_recorrentes_cobranca_automatica_indice'
            );
        });
    }

    public function down(): void
    {
        Schema::table('pagamentos_recorrentes', function (Blueprint $tabela): void {
            $tabela->dropIndex('pagamentos_recorrentes_cobranca_automatica_indice');
            $tabela->dropColumn('cobranca_automatica');
        });
    }
};
