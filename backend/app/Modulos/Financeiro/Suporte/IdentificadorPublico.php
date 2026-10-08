<?php

namespace App\Modulos\Financeiro\Suporte;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class IdentificadorPublico
{
    private const TABELAS = [
        'contas', 'categorias', 'lotes_importacao', 'linhas_importacao', 'transacoes',
        'pagamentos_recorrentes', 'metas_financeiras', 'aportes_metas', 'emprestimos',
        'parcelas_emprestimo',
    ];

    public static function novo(): string
    {
        return (string) Str::uuid();
    }

    public function resolver(string $tabela, string $uuid, int $usuario): ?int
    {
        if (! in_array($tabela, self::TABELAS, true) || ! Str::isUuid($uuid)) {
            return null;
        }

        return match ($tabela) {
            'linhas_importacao' => $this->resolverLinha($uuid, $usuario),
            'parcelas_emprestimo' => $this->resolverParcela($uuid, $usuario),
            default => $this->resolverDireto($tabela, $uuid, $usuario),
        };
    }

    /** @param array<string, array<int, true>> $ids */
    public function mapear(array $ids): array
    {
        $resultado = [];
        foreach ($ids as $tabela => $valores) {
            if (! in_array($tabela, self::TABELAS, true) || $valores === []) {
                continue;
            }
            $resultado[$tabela] = DB::table($tabela)->whereIn('id', array_keys($valores))
                ->pluck('uuid', 'id')->mapWithKeys(fn ($uuid, $id) => [(int) $id => $uuid])->all();
        }

        return $resultado;
    }

    private function resolverDireto(string $tabela, string $uuid, int $usuario): ?int
    {
        return $this->identificador(DB::table($tabela)->where('usuario_id', $usuario)->where('uuid', $uuid));
    }

    private function resolverLinha(string $uuid, int $usuario): ?int
    {
        $consulta = DB::table('linhas_importacao as linha')
            ->join('lotes_importacao as lote', 'lote.id', '=', 'linha.lote_id')
            ->where('lote.usuario_id', $usuario)->where('linha.uuid', $uuid)
            ->select('linha.id');

        return $this->identificador($consulta);
    }

    private function resolverParcela(string $uuid, int $usuario): ?int
    {
        $consulta = DB::table('parcelas_emprestimo as parcela')
            ->join('emprestimos as emprestimo', 'emprestimo.id', '=', 'parcela.emprestimo_id')
            ->where('emprestimo.usuario_id', $usuario)->where('parcela.uuid', $uuid)
            ->select('parcela.id');

        return $this->identificador($consulta);
    }

    private function identificador(Builder $consulta): ?int
    {
        $id = $consulta->value('id');

        return $id === null ? null : (int) $id;
    }
}
