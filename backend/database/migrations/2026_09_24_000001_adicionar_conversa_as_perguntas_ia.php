<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perguntas_ia', function (Blueprint $tabela): void {
            $tabela->uuid('conversa_id')->nullable()->after('usuario_id');
            $tabela->text('dados_usados_cifrados')->nullable()->after('dados_usados');
        });
        DB::table('perguntas_ia')->select('id')->orderBy('id')->eachById(function (object $pergunta): void {
            DB::table('perguntas_ia')->where('id', $pergunta->id)->update(['conversa_id' => (string) Str::uuid()]);
        });
        Schema::table('perguntas_ia', function (Blueprint $tabela): void {
            $tabela->index(['usuario_id', 'conversa_id', 'id'], 'perguntas_ia_conversa_indice');
        });
    }

    public function down(): void
    {
        Schema::table('perguntas_ia', function (Blueprint $tabela): void {
            $tabela->dropIndex('perguntas_ia_conversa_indice');
            $tabela->dropColumn(['conversa_id', 'dados_usados_cifrados']);
        });
    }
};
