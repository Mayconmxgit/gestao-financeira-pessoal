<?php

namespace App\Modulos\Administracao\Servicos;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RegistradorCicloAssinatura
{
    public function registrar(int $assinatura, ?string $anterior, string $nova, string $origem): void
    {
        if ($anterior === $nova) {
            return;
        }

        DB::table('eventos_ciclo_assinatura')->insert([
            'uuid' => (string) Str::uuid(), 'assinatura_id' => $assinatura,
            'situacao_anterior' => $anterior, 'situacao_nova' => $nova,
            'origem' => $origem, 'ocorrido_em' => now(),
        ]);
    }
}
