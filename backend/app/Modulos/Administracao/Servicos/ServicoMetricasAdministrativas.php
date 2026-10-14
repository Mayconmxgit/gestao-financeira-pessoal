<?php

namespace App\Modulos\Administracao\Servicos;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ServicoMetricasAdministrativas
{
    private const ATIVAS = ['ativa', 'periodo_graca'];

    public function dashboard(string $intervalo, string $referencia, ?string $de = null, ?string $ate = null): array
    {
        [$inicio, $fim] = $this->limites($intervalo, $referencia, $de, $ate);
        $assinaturas = DB::table('assinaturas')->whereIn('situacao', self::ATIVAS)->get();
        $mrr = $this->mrr($assinaturas);
        $base = DB::table('metricas_admin_diarias')->where('data', '<', $inicio->toDateString())
            ->orderByDesc('data')->first();
        $cancelamentos = DB::table('eventos_ciclo_assinatura')->where('situacao_nova', 'cancelada')
            ->whereBetween('ocorrido_em', [$inicio->startOfDay(), $fim->endOfDay()])->count();
        $ativacoes = DB::table('eventos_ciclo_assinatura')->where('situacao_nova', 'ativa')
            ->whereBetween('ocorrido_em', [$inicio->startOfDay(), $fim->endOfDay()])->count();
        $assinaturasCriadas = DB::table('assinaturas')
            ->whereBetween('created_at', [$inicio->startOfDay(), $fim->endOfDay()])->count();
        $totalWebhooks = DB::table('eventos_webhook_assinatura')
            ->whereBetween('created_at', [$inicio->startOfDay(), $fim->endOfDay()])->count();
        $falhasWebhooks = DB::table('eventos_webhook_assinatura')->where('situacao', 'falha')
            ->whereBetween('created_at', [$inicio->startOfDay(), $fim->endOfDay()])->count();

        return [
            'medicao_desde' => $this->medicaoDesde(),
            'periodo' => ['inicio' => $inicio->toDateString(), 'fim' => $fim->toDateString(), 'intervalo' => $intervalo],
            'resumo' => [
                'usuarios_total' => DB::table('usuarios')->where('papel', '!=', 'administrador')->count(),
                'novos_usuarios' => DB::table('usuarios')->where('papel', '!=', 'administrador')
                    ->whereBetween('criado_em', [$inicio->startOfDay(), $fim->endOfDay()])->count(),
                'usuarios_financeiramente_ativos' => DB::table('transacoes')->where('situacao', 'confirmada')
                    ->whereBetween('data_efetiva', [$inicio->toDateString(), $fim->toDateString()])
                    ->distinct()->count('usuario_id'),
                'assinaturas_ativas' => $assinaturas->count(),
                'assinaturas_pagas' => $assinaturas->filter(fn ($item) => $item->provedor === 'mercado_pago' && (float) $item->valor_contratado > 0)->count(),
                'assinaturas_gratuitas' => $assinaturas->where('provedor', 'gratuito')->count(),
                'assinaturas_cortesias' => $assinaturas->where('provedor', 'cortesia')->count(),
                'mrr_contratado' => $mrr,
                'arr_projetado' => number_format((float) $mrr * 12, 2, '.', ''),
                'churn_percentual' => $this->percentual($cancelamentos, $base?->assinaturas_ativas),
                'conversao_percentual' => $this->percentual($ativacoes, $assinaturasCriadas),
                'falha_webhooks_percentual' => $this->percentual($falhasWebhooks, $totalWebhooks),
            ],
            'crescimento' => $this->crescimento($intervalo, $inicio, $fim),
            'planos' => $this->distribuicaoPlanos($assinaturas),
            'alertas' => [
                'assinaturas_pendentes' => DB::table('assinaturas')->where('situacao', 'pendente')->count(),
                'assinaturas_inadimplentes' => DB::table('assinaturas')->where('situacao', 'inadimplente')->count(),
                'falhas_webhook' => $falhasWebhooks,
            ],
        ];
    }

    public function relatorio(string $tipo, string $intervalo, string $referencia, ?string $de = null, ?string $ate = null): array
    {
        [$inicio, $fim] = $this->limites($intervalo, $referencia, $de, $ate);
        if ($tipo === 'receita-recorrente') {
            return [
                'tipo' => $tipo, 'medicao_desde' => $this->medicaoDesde(),
                'serie' => $this->serieMrr($intervalo, $inicio, $fim),
                'tabela' => DB::table('planos_assinatura as plano')
                    ->leftJoin('metricas_admin_planos_diarias as metrica', function ($join) use ($fim): void {
                        $join->on('metrica.plano_id', '=', 'plano.id')->where('metrica.data', $fim->toDateString());
                    })->select('plano.nome', 'metrica.assinaturas_ativas', 'metrica.mrr_contratado')
                    ->orderBy('plano.nome')->get()->map(fn ($item) => [
                        'plano' => $item->nome, 'assinaturas_ativas' => $item->assinaturas_ativas === null ? null : (int) $item->assinaturas_ativas,
                        'mrr_contratado' => $item->mrr_contratado === null ? null : number_format((float) $item->mrr_contratado, 2, '.', ''),
                    ])->all(),
            ];
        }

        $dashboard = $this->dashboard($intervalo, $referencia, $de, $ate);

        return match ($tipo) {
            'crescimento' => ['tipo' => $tipo, 'medicao_desde' => $dashboard['medicao_desde'], 'serie' => $dashboard['crescimento'], 'tabela' => $dashboard['crescimento']],
            'churn' => ['tipo' => $tipo, 'medicao_desde' => $dashboard['medicao_desde'], 'resumo' => ['churn_percentual' => $dashboard['resumo']['churn_percentual']], 'serie' => $this->serieChurn($intervalo, $inicio, $fim), 'tabela' => []],
            'operacoes' => ['tipo' => $tipo, 'medicao_desde' => $dashboard['medicao_desde'], 'resumo' => $dashboard['alertas'], 'serie' => [], 'tabela' => $this->operacoes(100)['webhooks']],
        };
    }

    public function operacoes(int $limite): array
    {
        return [
            'webhooks' => DB::table('eventos_webhook_assinatura')->orderByDesc('id')->limit($limite)->get()->map(fn ($evento) => [
                'id' => $evento->uuid, 'tipo' => $evento->tipo, 'acao' => $evento->acao,
                'situacao' => $evento->situacao, 'codigo_erro' => $evento->codigo_erro,
                'recebido_em' => $evento->created_at, 'processado_em' => $evento->processado_em,
            ])->all(),
            'auditoria' => DB::table('auditoria_assinaturas as auditoria')
                ->join('usuarios as administrador', 'administrador.id', '=', 'auditoria.administrador_id')
                ->orderByDesc('auditoria.id')->limit($limite)->get([
                    'auditoria.acao', 'auditoria.entidade', 'auditoria.entidade_uuid as entidade_id',
                    'auditoria.criado_em', 'administrador.nome as administrador',
                ])->map(fn ($item) => (array) $item)->all(),
        ];
    }

    private function crescimento(string $intervalo, CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $periodos = $this->periodos($intervalo, $inicio, $fim);
        $usuarios = DB::table('usuarios')->where('papel', '!=', 'administrador')
            ->whereBetween('criado_em', [$inicio->startOfDay(), $fim->endOfDay()])->pluck('criado_em');
        $eventos = DB::table('eventos_ciclo_assinatura')->whereIn('situacao_nova', ['ativa', 'cancelada'])
            ->whereBetween('ocorrido_em', [$inicio->startOfDay(), $fim->endOfDay()])->get();

        return array_map(function (array $periodo) use ($usuarios, $eventos): array {
            $de = CarbonImmutable::parse($periodo['inicio'])->startOfDay();
            $ate = CarbonImmutable::parse($periodo['fim'])->endOfDay();

            return [
                'periodo' => $periodo['chave'], 'rotulo' => $periodo['rotulo'],
                'novos_usuarios' => $usuarios->filter(fn ($data) => CarbonImmutable::parse($data)->betweenIncluded($de, $ate))->count(),
                'ativacoes' => $eventos->filter(fn ($e) => $e->situacao_nova === 'ativa' && CarbonImmutable::parse($e->ocorrido_em)->betweenIncluded($de, $ate))->count(),
                'cancelamentos' => $eventos->filter(fn ($e) => $e->situacao_nova === 'cancelada' && CarbonImmutable::parse($e->ocorrido_em)->betweenIncluded($de, $ate))->count(),
            ];
        }, $periodos);
    }

    private function serieMrr(string $intervalo, CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $snapshots = DB::table('metricas_admin_diarias')->whereBetween('data', [$inicio->toDateString(), $fim->toDateString()])
            ->orderBy('data')->get()->keyBy(fn ($item) => (string) $item->data);

        return array_map(function (array $periodo) use ($snapshots): array {
            $item = strlen($periodo['chave']) === 7
                ? $snapshots->filter(fn ($snapshot, $data) => str_starts_with($data, substr($periodo['chave'], 0, 7)))->last()
                : $snapshots->get($periodo['chave']);

            return ['periodo' => $periodo['chave'], 'rotulo' => $periodo['rotulo'],
                'mrr_contratado' => $item ? number_format((float) $item->mrr_contratado, 2, '.', '') : null];
        }, $this->periodos($intervalo, $inicio, $fim));
    }

    private function serieChurn(string $intervalo, CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $snapshots = DB::table('metricas_admin_diarias')->where('data', '<=', $fim->toDateString())->orderBy('data')->get();
        $eventos = DB::table('eventos_ciclo_assinatura')->where('situacao_nova', 'cancelada')
            ->whereBetween('ocorrido_em', [$inicio->startOfDay(), $fim->endOfDay()])->pluck('ocorrido_em');

        return array_map(function (array $item) use ($snapshots, $eventos): array {
            $de = CarbonImmutable::parse($item['inicio'])->startOfDay();
            $ate = CarbonImmutable::parse($item['fim'])->endOfDay();
            $base = $snapshots->filter(fn ($snapshot) => CarbonImmutable::parse($snapshot->data)->lt($de))->last();
            $cancelamentos = $eventos->filter(fn ($data) => CarbonImmutable::parse($data)->betweenIncluded($de, $ate))->count();

            return ['periodo' => $item['chave'], 'rotulo' => $item['rotulo'],
                'churn_percentual' => $this->percentual($cancelamentos, $base?->assinaturas_ativas)];
        }, $this->periodos($intervalo, $inicio, $fim));
    }

    private function distribuicaoPlanos(Collection $assinaturas): array
    {
        $nomes = DB::table('planos_assinatura')->pluck('nome', 'id');

        return $assinaturas->groupBy('plano_id')->map(fn ($itens, $id) => [
            'plano' => $nomes[$id] ?? 'Plano indisponível', 'assinaturas' => $itens->count(), 'mrr_contratado' => $this->mrr($itens),
        ])->values()->all();
    }

    private function periodos(string $intervalo, CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $resultado = [];
        if ($intervalo === '12m' || ($intervalo === 'personalizado' && $inicio->diffInDays($fim) > 90)) {
            for ($cursor = $inicio->startOfMonth(); $cursor <= $fim; $cursor = $cursor->addMonth()) {
                $resultado[] = ['chave' => $cursor->format('Y-m'), 'rotulo' => $cursor->translatedFormat('M/y'),
                    'inicio' => $cursor->toDateString(), 'fim' => $cursor->endOfMonth()->min($fim)->toDateString()];
            }

            return $resultado;
        }
        for ($cursor = $inicio; $cursor <= $fim; $cursor = $cursor->addDay()) {
            $resultado[] = ['chave' => $cursor->toDateString(), 'rotulo' => $cursor->format('d/m'),
                'inicio' => $cursor->toDateString(), 'fim' => $cursor->toDateString()];
        }

        return $resultado;
    }

    private function limites(string $intervalo, string $referencia, ?string $de = null, ?string $ate = null): array
    {
        if ($intervalo === 'personalizado') {
            return [CarbonImmutable::parse($de)->startOfDay(), CarbonImmutable::parse($ate)->startOfDay()];
        }

        $fim = CarbonImmutable::parse($referencia)->startOfDay();
        $inicio = match ($intervalo) {
            '7d' => $fim->subDays(6), '30d' => $fim->subDays(29), '90d' => $fim->subDays(89),
            '12m' => $fim->subMonths(11)->startOfMonth(),
        };

        return [$inicio, $fim];
    }

    private function mrr(iterable $assinaturas): string
    {
        $total = 0.0;
        foreach ($assinaturas as $assinatura) {
            $total += $assinatura->periodicidade === 'anual'
                ? ((float) $assinatura->valor_contratado / 12) : (float) $assinatura->valor_contratado;
        }

        return number_format($total, 2, '.', '');
    }

    private function percentual(int $numerador, ?int $denominador): ?string
    {
        return empty($denominador) ? null : number_format(($numerador / $denominador) * 100, 2, '.', '');
    }

    private function medicaoDesde(): ?string
    {
        $snapshot = DB::table('metricas_admin_diarias')->min('data');
        $evento = DB::table('eventos_ciclo_assinatura')->min('ocorrido_em');
        $datas = array_filter([$snapshot, $evento]);

        return $datas === [] ? null : CarbonImmutable::parse(min($datas))->toDateString();
    }
}
