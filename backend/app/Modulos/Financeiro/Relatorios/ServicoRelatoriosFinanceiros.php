<?php

namespace App\Modulos\Financeiro\Relatorios;

use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ServicoRelatoriosFinanceiros
{
    private const ENTRADAS = "SUM(CASE WHEN transacoes.valor > 0 AND transacoes.natureza NOT IN ('transferencia','pagamento_fatura','ajuste','emprestimo_recebido') THEN transacoes.valor ELSE 0 END)";

    private const SAIDAS = "SUM(CASE WHEN transacoes.valor < 0 AND transacoes.natureza NOT IN ('transferencia','pagamento_fatura','ajuste','pagamento_emprestimo') THEN -transacoes.valor ELSE 0 END)";

    private const DESPESAS = "SUM(CASE WHEN transacoes.valor < 0 AND transacoes.natureza IN ('saida','compra_cartao') THEN -transacoes.valor WHEN transacoes.valor > 0 AND transacoes.natureza = 'estorno' THEN -transacoes.valor ELSE 0 END)";

    public function __construct(private readonly ConstrutorPeriodosRelatorio $periodos) {}

    public function evolucao(int $usuario, array $dados): array
    {
        $intervalo = $this->periodos->evolucao($dados['intervalo'], $dados['referencia']);

        return $this->calcular($usuario, $dados['moeda'], $intervalo);
    }

    public function comparar(int $usuario, array $dados): array
    {
        $a = $this->calcular($usuario, $dados['moeda'], $this->periodos->mes($dados['mes_a']));
        $b = $this->calcular($usuario, $dados['moeda'], $this->periodos->mes($dados['mes_b']));

        return [
            'mes_a' => $dados['mes_a'], 'mes_b' => $dados['mes_b'], 'moeda' => $dados['moeda'],
            'nao_categorizado' => $this->variacao($a['nao_categorizado'], $b['nao_categorizado']),
            'categorias' => $this->compararColecoes($a['categorias'], $b['categorias'], 'categoria_id', ['total']),
            'contas' => $this->compararColecoes($a['contas'], $b['contas'], 'conta_id', ['total_entradas', 'total_saidas', 'total_resultado', 'saldo_final']),
            'cartoes' => $this->compararColecoes($a['cartoes'], $b['cartoes'], 'conta_id', ['total_gastos']),
        ];
    }

    private function calcular(int $usuario, string $moeda, array $intervalo): array
    {
        $periodos = $intervalo['periodos'];

        return [
            'intervalo' => [...array_diff_key($intervalo, ['periodos' => true]), 'moeda' => $moeda],
            'periodos' => $periodos,
            'categorias' => $this->categorias($usuario, $moeda, $intervalo),
            'nao_categorizado' => $this->naoCategorizado($usuario, $moeda, $intervalo),
            'contas' => $this->contas($usuario, $moeda, $intervalo),
            'cartoes' => $this->cartoes($usuario, $moeda, $intervalo),
        ];
    }

    private function categorias(int $usuario, string $moeda, array $intervalo): array
    {
        $expressao = $this->expressaoPeriodo($intervalo['granularidade']);
        $linhas = $this->transacoes($usuario, $moeda, $intervalo)->join('categorias', 'categorias.id', '=', 'transacoes.categoria_id')
            ->where('categorias.usuario_id', $usuario)->where('categorias.tipo', 'despesa')
            ->select('categorias.id', 'categorias.nome')->selectRaw("$expressao periodo")
            ->selectRaw(self::DESPESAS.' total')->groupBy('categorias.id', 'categorias.nome')->groupByRaw($expressao)->get();
        $agrupadas = $linhas->groupBy('id')->map(fn ($itens) => $itens->keyBy('periodo'));

        return DB::table('categorias')->where('usuario_id', $usuario)->where('tipo', 'despesa')->get(['id', 'nome'])
            ->map(fn (object $categoria) => $this->serieCategoria($categoria, $agrupadas->get($categoria->id), $intervalo['periodos']))
            ->filter(fn (array $categoria) => bccomp($categoria['total'], '0', 2) > 0)
            ->sortByDesc('total', SORT_NUMERIC)->values()->all();
    }

    private function serieCategoria(object $categoria, mixed $valores, array $periodos): array
    {
        $total = '0.00';
        $pontos = array_map(function (array $periodo) use ($valores, &$total): array {
            $valor = Entrada::moeda((string) ($valores?->get($periodo['chave'])?->total ?? 0));
            $total = bcadd($total, $valor, 2);

            return ['periodo' => $periodo['chave'], 'total' => $valor];
        }, $periodos);

        return ['categoria_id' => (int) $categoria->id, 'nome' => $categoria->nome, 'total' => $total, 'pontos' => $pontos];
    }

    private function naoCategorizado(int $usuario, string $moeda, array $intervalo): string
    {
        $total = $this->transacoes($usuario, $moeda, $intervalo)->whereNull('transacoes.categoria_id')
            ->selectRaw(self::DESPESAS.' total')->value('total');

        return Entrada::moeda((string) ($total ?? 0));
    }

    private function contas(int $usuario, string $moeda, array $intervalo): array
    {
        $contas = DB::table('contas')->where('usuario_id', $usuario)->where('moeda', $moeda)
            ->where('tipo', '<>', 'cartao_credito')->orderBy('id')->get(['id', 'nome', 'tipo', 'saldo_inicial', 'situacao']);
        $anteriores = $this->saldosAnteriores($usuario, $moeda, $intervalo['inicio']);
        $movimentos = $this->movimentosContas($usuario, $moeda, $intervalo);

        return $contas->map(fn (object $conta) => $this->serieConta($conta, $anteriores, $movimentos, $intervalo['periodos']))->all();
    }

    private function saldosAnteriores(int $usuario, string $moeda, string $inicio): object
    {
        return DB::table('transacoes')->join('contas', 'contas.id', '=', 'transacoes.conta_id')
            ->where('transacoes.usuario_id', $usuario)->where('contas.moeda', $moeda)
            ->where('contas.tipo', '<>', 'cartao_credito')->where('transacoes.situacao', 'confirmada')
            ->where('transacoes.data_efetiva', '<', $inicio)->groupBy('transacoes.conta_id')
            ->select('transacoes.conta_id')->selectRaw('SUM(transacoes.valor) total')->pluck('total', 'conta_id');
    }

    private function movimentosContas(int $usuario, string $moeda, array $intervalo): object
    {
        $expressao = $this->expressaoPeriodo($intervalo['granularidade']);

        return $this->transacoes($usuario, $moeda, $intervalo)->where('contas.tipo', '<>', 'cartao_credito')
            ->select('transacoes.conta_id')->selectRaw("$expressao periodo")
            ->selectRaw(self::ENTRADAS.' entradas')->selectRaw(self::SAIDAS.' saidas')
            ->selectRaw('SUM(transacoes.valor) movimento')->groupBy('transacoes.conta_id')->groupByRaw($expressao)
            ->get()->groupBy('conta_id')->map(fn ($itens) => $itens->keyBy('periodo'));
    }

    private function serieConta(object $conta, object $anteriores, object $movimentos, array $periodos): array
    {
        $saldo = bcadd((string) $conta->saldo_inicial, (string) ($anteriores->get($conta->id) ?? 0), 2);
        $entradas = $saidas = '0.00';
        $pontos = [];
        foreach ($periodos as $periodo) {
            $linha = $movimentos->get($conta->id)?->get($periodo['chave']);
            $pontos[] = $this->pontoConta($periodo['chave'], $linha, $saldo);
            $saldo = $pontos[array_key_last($pontos)]['saldo_final'];
            $entradas = bcadd($entradas, $pontos[array_key_last($pontos)]['entradas'], 2);
            $saidas = bcadd($saidas, $pontos[array_key_last($pontos)]['saidas'], 2);
        }

        return $this->resumoConta($conta, $entradas, $saidas, $saldo, $pontos);
    }

    private function pontoConta(string $periodo, ?object $linha, string $saldo): array
    {
        $entradas = Entrada::moeda((string) ($linha?->entradas ?? 0));
        $saidas = Entrada::moeda((string) ($linha?->saidas ?? 0));

        return [
            'periodo' => $periodo, 'entradas' => $entradas, 'saidas' => $saidas,
            'resultado_liquido' => bcsub($entradas, $saidas, 2),
            'saldo_final' => bcadd($saldo, (string) ($linha?->movimento ?? 0), 2),
        ];
    }

    private function resumoConta(object $conta, string $entradas, string $saidas, string $saldo, array $pontos): array
    {
        return [
            'conta_id' => (int) $conta->id, 'nome' => $conta->nome, 'tipo' => $conta->tipo,
            'situacao' => $conta->situacao, 'total_entradas' => $entradas, 'total_saidas' => $saidas,
            'total_resultado' => bcsub($entradas, $saidas, 2), 'saldo_final' => $saldo, 'pontos' => $pontos,
        ];
    }

    private function cartoes(int $usuario, string $moeda, array $intervalo): array
    {
        $expressao = $this->expressaoPeriodo($intervalo['granularidade']);
        $linhas = $this->transacoes($usuario, $moeda, $intervalo)->where('contas.tipo', 'cartao_credito')
            ->select('contas.id', 'contas.nome', 'contas.situacao')->selectRaw("$expressao periodo")
            ->selectRaw(self::DESPESAS.' gastos')->groupBy('contas.id', 'contas.nome', 'contas.situacao')->groupByRaw($expressao)->get();
        $agrupados = $linhas->groupBy('id')->map(fn ($itens) => $itens->keyBy('periodo'));

        return DB::table('contas')->where('usuario_id', $usuario)->where('moeda', $moeda)->where('tipo', 'cartao_credito')
            ->orderBy('id')->get(['id', 'nome', 'situacao'])
            ->map(fn (object $cartao) => $this->serieCartao($cartao, $agrupados->get($cartao->id), $intervalo['periodos']))->all();
    }

    private function serieCartao(object $cartao, mixed $valores, array $periodos): array
    {
        $total = '0.00';
        $pontos = array_map(function (array $periodo) use ($valores, &$total): array {
            $gastos = Entrada::moeda((string) ($valores?->get($periodo['chave'])?->gastos ?? 0));
            $total = bcadd($total, $gastos, 2);

            return ['periodo' => $periodo['chave'], 'gastos' => $gastos];
        }, $periodos);

        return ['conta_id' => (int) $cartao->id, 'nome' => $cartao->nome, 'situacao' => $cartao->situacao, 'total_gastos' => $total, 'pontos' => $pontos];
    }

    private function transacoes(int $usuario, string $moeda, array $intervalo): Builder
    {
        return DB::table('transacoes')->join('contas', 'contas.id', '=', 'transacoes.conta_id')
            ->where('transacoes.usuario_id', $usuario)->where('contas.usuario_id', $usuario)
            ->where('contas.moeda', $moeda)->where('transacoes.situacao', 'confirmada')
            ->whereBetween('transacoes.data_efetiva', [$intervalo['inicio'], $intervalo['fim']]);
    }

    private function expressaoPeriodo(string $granularidade): string
    {
        if ($granularidade === 'diaria') {
            return 'transacoes.data_efetiva';
        }

        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', transacoes.data_efetiva)"
            : "DATE_FORMAT(transacoes.data_efetiva, '%Y-%m')";
    }

    private function compararColecoes(array $a, array $b, string $identificador, array $metricas): array
    {
        $itensA = collect($a)->keyBy($identificador);
        $itensB = collect($b)->keyBy($identificador);

        return $itensA->keys()->merge($itensB->keys())->unique()->map(function ($id) use ($itensA, $itensB, $identificador, $metricas): array {
            $itemA = $itensA->get($id, []);
            $itemB = $itensB->get($id, []);
            $base = $itemA + $itemB;
            $resultado = [$identificador => $id, 'nome' => $base['nome'], ...array_filter(['tipo' => $base['tipo'] ?? null, 'situacao' => $base['situacao'] ?? null])];
            foreach ($metricas as $metrica) {
                $chave = str_replace('total_', '', $metrica);
                $variacao = $this->variacao((string) ($itemA[$metrica] ?? 0), (string) ($itemB[$metrica] ?? 0));
                $resultado = $metrica === 'total' ? [...$resultado, ...$variacao] : [...$resultado, $chave => $variacao];
            }

            return $resultado;
        })->sortByDesc(fn (array $item) => (float) ($item['valor_b'] ?? ($item['saidas'] ?? $item['gastos'])['valor_b'] ?? 0))->values()->all();
    }

    private function variacao(string $a, string $b): array
    {
        return [
            'valor_a' => Entrada::moeda($a), 'valor_b' => Entrada::moeda($b),
            'variacao_valor' => bcsub($b, $a, 2),
            'variacao_percentual' => bccomp($a, '0', 2) === 0 ? null : bcdiv(bcmul(bcsub($b, $a, 4), '100', 4), $a, 2),
        ];
    }
}
