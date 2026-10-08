<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emprestimos', function (Blueprint $tabela): void {
            $tabela->date('data_base_calculo')->nullable()->after('data_recebimento');
        });
    }

    public function down(): void
    {
        Schema::table('emprestimos', function (Blueprint $tabela): void {
            $tabela->dropColumn('data_base_calculo');
        });
    }
};
