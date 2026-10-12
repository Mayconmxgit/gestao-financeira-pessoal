<?php

namespace App\Modulos\Financeiro\Resumo;

use App\Modulos\Financeiro\Cartoes\ServicoFaturasCartao;
use App\Modulos\Financeiro\Emprestimos\IndicadoresDividas;
use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ServicoResumoFinanceiro
{
    public function __construct(
        private readonly IndicadoresDividas $indicadoresDividas,
        private readonly ProjecaoCompromissosProximoMes $projecao,
        private readonly ServicoFaturasCartao $faturasCartao,
    ) {}

    public function calcular(int $usuario, array $dados): array
    {
        $contas = $this->contas($usuario, $dados);
        $totais = array_fill_keys(['saldo_inicial', 'saldo_final', 'entradas', 'saidas', 'receitas', 'despesas', 'transferencias', 'ajustes', 'pendentes'], '0.00');
        $porConta = [];
        foreach ($contas as $conta) {
            $linha = $this->resumirConta($usuario, $conta, $dados['inicio'], $dados['fim']);
            $porConta[] = $linha;
            $this->somarTotais($totais, $linha, $conta->tipo);
        }
        $moeda = $contas->first()?->moeda ?? ($dados['moeda'] ?? null);

        return [
            'inicio' => $dados['inicio'], 'fim' => $dados['fim'], 'consolidado' => $totais, 'contas' => $porConta,
            'despesas_por_categoria' => $this->despesasPorCategoria(
                $usuario, $contas->pluck('id')->all(), $dados['inicio'], $dados['fim'], (string) $totais['despesas'],
            ),
            'dividas' => $this->indicadoresDividas->calcular($usuario, $moeda, (string) $totais['receitas']),
            'compromissos_proximo_mes' => $this->projecao->calcular($usuario, (string) $moeda, $dados['fim']),
            'faturas_cartao' => $this->faturasCartao->resumo($usuario, (string) $moeda),
        ];
    }

    private function contas(int $usuario, array $dados): object
    {
        $contas = DB::table('contas')->where('usuario_id', $usuario)
            ->when(isset($dados['conta_id']), fn ($consulta) => $consulta->where('id', $dados['conta_id']))
            ->when(isset($dados['moeda']), fn ($consulta) => $consulta->where('moeda', $dados['moeda']))
            ->orderBy('id')->get();
        if (isset($dados['conta_id']) && $contas->isEmpty()) {
            abort(404);
        }
        if ($contas->pluck('moeda')->unique()->count() > 1) {
            throw ValidationException::withMessages(['conta_id' => 'Selecione uma conta; moedas diferentes não podem ser somadas sem conversão.']);
        }

        return $contas;
    }

    private function resumirConta(int $usuario, object $conta, string $inicio, string $fim): array
    {
        $base = DB::table('transacoes')->where('usuario_id', $usuario)
            ->where('conta_id', $conta->id)->where('situacao', 'confirmada');
        $antes = (string) (clone $base)->where('data_efetiva', '<', $inicio)->sum('valor');
        $periodo = (string) (clone $base)->whereBetween('data_efetiva', [$inicio, $fim])->sum('valor');
        $saldoInicial = bcadd((string) $conta->saldo_inicial, $antes, 2);
        $resumo = $this->totaisConta($usuario, $conta->id, $inicio, $fim);

        return [
            'conta_id' => $conta->id, 'nome' => $conta->nome, 'tipo' => $conta->tipo, 'moeda' => $conta->moeda,
            'saldo_inicial' => $saldoInicial, 'saldo_final' => bcadd($saldoInicial, $periodo, 2),
            'entradas' => Entrada::moeda((string) $resumo->entradas), 'saidas' => Entrada::moeda((string) $resumo->saidas),
            'receitas' => Entrada::moeda((string) $resumo->receitas), 'despesas' => Entrada::moeda((string) $resumo->despesas),
            'transferencias' => Entrada::moeda((string) $resumo->transferencias), 'ajustes' => Entrada::moeda((string) $resumo->ajustes),
            'pendentes' => $this->pendentes($usuario, $conta->id, $inicio, $fim),
        ];
    }

    private function totaisConta(int $usuario, int $conta, string $inicio, string $fim): object
    {
        return DB::table('transacoes')->where('usuario_id', $usuario)->where('conta_id', $conta)
            ->where('situacao', 'confirmada')->whereBetween('data_efetiva', [$inicio, $fim])
            ->selectRaw("COALESCE(SUM(CASE WHEN valor > 0 AND natureza NOT IN ('transferencia','pagamento_fatura','ajuste','emprestimo_recebido') THEN valor ELSE 0 END),0) entradas")
            ->selectRaw("COALESCE(SUM(CASE WHEN valor < 0 AND natureza NOT IN ('transferencia','pagamento_fatura','ajuste','pagamento_emprestimo') THEN -valor ELSE 0 END),0) saidas")
            ->selectRaw("COALESCE(SUM(CASE WHEN valor > 0 AND categoria_id IS NOT NULL AND natureza IN ('entrada') THEN valor WHEN valor < 0 AND categoria_id IS NOT NULL AND natureza = 'estorno' THEN valor ELSE 0 END),0) receitas")
            ->selectRaw("COALESCE(SUM(CASE WHEN valor < 0 AND categoria_id IS NOT NULL AND natureza IN ('saida','compra_cartao') THEN -valor WHEN valor > 0 AND categoria_id IS NOT NULL AND natureza = 'estorno' THEN -valor ELSE 0 END),0) despesas")
            ->selectRaw("COALESCE(SUM(CASE WHEN natureza IN ('transferencia','pagamento_fatura') THEN valor ELSE 0 END),0) transferencias")
            ->selectRaw("COALESCE(SUM(CASE WHEN natureza = 'ajuste' THEN valor ELSE 0 END),0) ajustes")
            ->first();
    }

    private function pendentes(int $usuario, int $conta, string $inicio, string $fim): int
    {
        return DB::table('transacoes')->where('usuario_id', $usuario)->where('conta_id', $conta)
            ->where('situacao', 'confirmada')->whereNull('categoria_id')
            ->whereIn('natureza', ['entrada', 'saida', 'compra_cartao', 'estorno'])
            ->whereBetween('data_efetiva', [$inicio, $fim])->count();
    }

    private function somarTotais(array &$totais, array $linha, string $tipoConta): void
    {
        foreach ($totais as $chave => $valor) {
            if (in_array($chave, ['saldo_inicial', 'saldo_final'], true) && $tipoConta === 'cartao_credito') {
                continue;
            }
            $totais[$chave] = $chave === 'pendentes' ? $valor + $linha[$chave] : bcadd($valor, (string) $linha[$chave], 2);
        }
    }

    private function despesasPorCategoria(int $usuario, array $contas, string $inicio, string $fim, string $total): array
    {
        if ($contas === [] || bccomp($total, '0', 2) <= 0) {
            return [];
        }
        $categorias = DB::table('transacoes')->join('categorias', 'categorias.id', '=', 'transacoes.categoria_id')
            ->where('transacoes.usuario_id', $usuario)->where('categorias.usuario_id', $usuario)
            ->where('categorias.tipo', 'despesa')->where('transacoes.situacao', 'confirmada')
            ->whereIn('transacoes.conta_id', $contas)->whereBetween('transacoes.data_efetiva', [$inicio, $fim])
            ->groupBy('categorias.id', 'categorias.nome', 'categorias.limite_gastos')
            ->select('categorias.id', 'categorias.nome', 'categorias.limite_gastos')
            ->selectRaw("SUM(CASE WHEN transacoes.valor < 0 AND transacoes.natureza IN ('saida','compra_cartao') THEN -transacoes.valor WHEN transacoes.valor > 0 AND transacoes.natureza = 'estorno' THEN -transacoes.valor ELSE 0 END) total")
            ->orderByDesc('total')->get();

        return $categorias->filter(fn (object $item): bool => bccomp((string) $item->total, '0', 2) > 0)
            ->map(fn (object $item): array => $this->resumirCategoria($item, $total))->values()->all();
    }

    private function resumirCategoria(object $categoria, string $totalDespesas): array
    {
        $total = Entrada::moeda((string) $categoria->total);
        $limite = $categoria->limite_gastos === null ? null : Entrada::moeda((string) $categoria->limite_gastos);

        return [
            'categoria_id' => (int) $categoria->id,
            'nome' => $categoria->nome,
            'total' => $total,
            'percentual' => bcdiv(bcmul($total, '100', 4), $totalDespesas, 2),
            'limite_gastos' => $limite,
            'percentual_limite' => $limite === null ? null : bcdiv(bcmul($total, '100', 4), $limite, 2),
            'limite_ultrapassado' => $limite !== null && bccomp($total, $limite, 2) > 0,
        ];
    }
}
