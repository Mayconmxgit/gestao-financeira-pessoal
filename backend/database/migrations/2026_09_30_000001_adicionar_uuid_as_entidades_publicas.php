<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const TABELAS = [
        'usuarios', 'contas', 'categorias', 'lotes_importacao', 'linhas_importacao',
        'transacoes', 'pagamentos_recorrentes', 'metas_financeiras', 'aportes_metas',
        'emprestimos', 'parcelas_emprestimo',
    ];

    public function up(): void
    {
        foreach (self::TABELAS as $tabela) {
            Schema::table($tabela, function (Blueprint $estrutura) use ($tabela): void {
                $estrutura->uuid('uuid')->nullable()->unique("{$tabela}_uuid_unico");
            });

            DB::table($tabela)->whereNull('uuid')->orderBy('id')->chunkById(500, function ($registros) use ($tabela): void {
                foreach ($registros as $registro) {
                    DB::table($tabela)->where('id', $registro->id)->update(['uuid' => (string) Str::uuid()]);
                }
            });

            Schema::table($tabela, function (Blueprint $estrutura): void {
                $estrutura->uuid('uuid')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABELAS) as $tabela) {
            Schema::table($tabela, function (Blueprint $estrutura) use ($tabela): void {
                $estrutura->dropUnique("{$tabela}_uuid_unico");
                $estrutura->dropColumn('uuid');
            });
        }
    }
};
