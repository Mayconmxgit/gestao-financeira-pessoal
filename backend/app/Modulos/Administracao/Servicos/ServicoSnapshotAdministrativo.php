<?php

namespace App\Modulos\Administracao\Servicos;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class ServicoSnapshotAdministrativo
{
    private const ATIVAS = ['ativa', 'periodo_graca'];

    public function capturar(CarbonInterface $referencia): void
    {
        $data = $referencia->toDateString();
        DB::transaction(function () use ($data, $referencia): void {
            $assinaturas = DB::table('assinaturas')->whereIn('situacao', self::ATIVAS)->get();
            $situacoes = DB::table('assinaturas')->selectRaw('situacao, COUNT(*) total')
                ->groupBy('situacao')->pluck('total', 'situacao');
            $webhooksFalhos = DB::table('eventos_webhook_assinatura')->whereDate('created_at', $data)
                ->where('situacao', 'falha')->count();
            DB::table('metricas_admin_diarias')->upsert([[
                'data' => $data,
                'usuarios_total' => DB::table('usuarios')->where('papel', '!=', 'administrador')->count(),
                'usuarios_ativos_30d' => DB::table('transacoes')->where('situacao', 'confirmada')
                    ->whereBetween('data_efetiva', [$referencia->copy()->subDays(29)->toDateString(), $data])
                    ->distinct()->count('usuario_id'),
                'assinaturas_ativas' => $assinaturas->count(),
                'assinaturas_pagas' => $assinaturas->filter(fn ($item) => $item->provedor === 'mercado_pago' && (float) $item->valor_contratado > 0)->count(),
                'assinaturas_gratuitas' => $assinaturas->where('provedor', 'gratuito')->count(),
                'assinaturas_cortesias' => $assinaturas->where('provedor', 'cortesia')->count(),
                'assinaturas_pendentes' => (int) ($situacoes['pendente'] ?? 0),
                'assinaturas_inadimplentes' => (int) ($situacoes['inadimplente'] ?? 0),
                'falhas_webhook' => $webhooksFalhos,
                'mrr_contratado' => $this->mrr($assinaturas),
                'created_at' => now(), 'updated_at' => now(),
            ]], ['data'], [
                'usuarios_total', 'usuarios_ativos_30d', 'assinaturas_ativas', 'assinaturas_pagas',
                'assinaturas_gratuitas', 'assinaturas_cortesias', 'assinaturas_pendentes',
                'assinaturas_inadimplentes', 'falhas_webhook', 'mrr_contratado', 'updated_at',
            ]);

            $porPlano = $assinaturas->groupBy('plano_id');
            $planos = DB::table('planos_assinatura')->pluck('id');
            foreach ($planos as $plano) {
                $itens = $porPlano->get($plano, collect());
                DB::table('metricas_admin_planos_diarias')->upsert([[
                    'data' => $data, 'plano_id' => $plano, 'assinaturas_ativas' => $itens->count(),
                    'mrr_contratado' => $this->mrr($itens), 'created_at' => now(), 'updated_at' => now(),
                ]], ['data', 'plano_id'], ['assinaturas_ativas', 'mrr_contratado', 'updated_at']);
            }
        });
    }

    private function mrr(iterable $assinaturas): string
    {
        $total = 0.0;
        foreach ($assinaturas as $assinatura) {
            $valor = (float) $assinatura->valor_contratado;
            $total += $assinatura->periodicidade === 'anual' ? $valor / 12 : $valor;
        }

        return number_format($total, 2, '.', '');
    }
}
