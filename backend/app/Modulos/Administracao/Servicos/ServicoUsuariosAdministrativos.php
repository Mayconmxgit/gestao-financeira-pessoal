<?php

namespace App\Modulos\Administracao\Servicos;

use Illuminate\Support\Facades\DB;

final class ServicoUsuariosAdministrativos
{
    public function listar(array $filtros): array
    {
        $consulta = DB::table('usuarios as usuario')
            ->leftJoin('assinaturas as assinatura', function ($juncao): void {
                $juncao->on('assinatura.usuario_id', '=', 'usuario.id')
                    ->whereRaw('assinatura.id = (SELECT MAX(a2.id) FROM assinaturas a2 WHERE a2.usuario_id = usuario.id)');
            })
            ->leftJoin('planos_assinatura as plano', 'plano.id', '=', 'assinatura.plano_id')
            ->where('usuario.papel', '!=', 'administrador')
            ->when($filtros['busca'] ?? null, function ($query, string $busca): void {
                $termo = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($busca)).'%';
                $query->where(fn ($q) => $q->where('usuario.nome', 'like', $termo)->orWhere('usuario.email', 'like', $termo));
            })
            ->when($filtros['situacao'] ?? null, fn ($q, $v) => $q->where('assinatura.situacao', $v))
            ->when($filtros['plano_id'] ?? null, fn ($q, $v) => $q->where('plano.uuid', $v))
            ->when($filtros['de'] ?? null, fn ($q, $v) => $q->whereDate('usuario.criado_em', '>=', $v))
            ->when($filtros['ate'] ?? null, fn ($q, $v) => $q->whereDate('usuario.criado_em', '<=', $v))
            ->when($filtros['apos'] ?? null, fn ($q, $v) => $q->where('usuario.uuid', '<', $v))
            ->select('usuario.uuid', 'usuario.nome', 'usuario.email', 'usuario.criado_em', 'assinatura.situacao',
                'assinatura.provedor', 'assinatura.periodo_acesso_fim', 'plano.nome as plano_nome')
            ->orderByDesc('usuario.uuid')->limit(($filtros['limite'] ?? 30) + 1)->get();
        $limite = $filtros['limite'] ?? 30;
        $pagina = $consulta->take($limite)->values();

        return [
            'dados' => $pagina->map(fn (object $usuario) => [
                'id' => $usuario->uuid, 'nome' => $usuario->nome, 'email' => $usuario->email,
                'criado_em' => $usuario->criado_em,
                'assinatura' => $usuario->situacao === null ? null : [
                    'plano' => $usuario->plano_nome, 'situacao' => $usuario->situacao,
                    'provedor' => $usuario->provedor, 'acesso_ate' => $usuario->periodo_acesso_fim,
                ],
            ]),
            'proximo_cursor' => $consulta->count() > $limite ? $pagina->last()?->uuid : null,
        ];
    }
}
