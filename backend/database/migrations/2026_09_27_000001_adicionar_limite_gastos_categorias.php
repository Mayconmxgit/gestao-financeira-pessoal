<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categorias', function (Blueprint $tabela): void {
            $tabela->decimal('limite_gastos', 18, 2)->nullable()->after('tipo');
        });
    }

    public function down(): void
    {
        Schema::table('categorias', function (Blueprint $tabela): void {
            $tabela->dropColumn('limite_gastos');
        });
    }
};
