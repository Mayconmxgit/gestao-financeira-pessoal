<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDICE = 'transacoes_usuario_codigo_unico';

    public function up(): void
    {
        Schema::table('transacoes', function (Blueprint $tabela): void {
            $tabela->char('codigo', 9)->nullable()->after('uuid');
        });

        DB::table('transacoes')->whereNull('codigo')->orderBy('id')->chunkById(500, function ($transacoes): void {
            foreach ($transacoes as $transacao) {
                DB::table('transacoes')->where('id', $transacao->id)->update([
                    'codigo' => $this->gerarCodigo((int) $transacao->usuario_id),
                ]);
            }
        });

        Schema::table('transacoes', function (Blueprint $tabela): void {
            $tabela->char('codigo', 9)->nullable(false)->change();
            $tabela->unique(['usuario_id', 'codigo'], self::INDICE);
        });
    }

    public function down(): void
    {
        Schema::table('transacoes', function (Blueprint $tabela): void {
            $tabela->dropUnique(self::INDICE);
            $tabela->dropColumn('codigo');
        });
    }

    private function gerarCodigo(int $usuario): string
    {
        for ($tentativa = 0; $tentativa < 20; $tentativa++) {
            $codigo = (string) random_int(100000000, 999999999);
            if (! DB::table('transacoes')->where('usuario_id', $usuario)->where('codigo', $codigo)->exists()) {
                return $codigo;
            }
        }

        throw new RuntimeException('Não foi possível gerar os códigos das movimentações existentes.');
    }
};
