<?php

namespace App\Modulos\Assinaturas\Servicos;

use Illuminate\Support\Facades\DB;

final class ServicoAcessoAssinatura
{
    public function possuiAcesso(int $usuario): bool
    {
        return $this->assinaturasValidas($usuario)->exists();
    }

    public function possuiRecurso(int $usuario, string $recurso): bool
    {
        return $this->assinaturasValidas($usuario)
            ->join('planos_assinatura as plano', 'plano.id', '=', 'assinatura.plano_id')
            ->pluck('plano.recursos')
            ->contains(function (mixed $recursos) use ($recurso): bool {
                $lista = json_decode((string) $recursos, true);

                return is_array($lista) && in_array($recurso, $lista, true);
            });
    }

    private function assinaturasValidas(int $usuario)
    {
        return DB::table('assinaturas as assinatura')
            ->where('assinatura.usuario_id', $usuario)
            ->whereIn('assinatura.situacao', ['ativa', 'periodo_graca'])
            ->where(function ($consulta): void {
                $consulta->whereNull('assinatura.periodo_acesso_fim')
                    ->orWhere('assinatura.periodo_acesso_fim', '>', now());
            });
    }
}
