<?php

namespace App\Modulos\Financeiro\Resumo;

use App\Modulos\Financeiro\Emprestimos\ClassificadorParcelas;
use App\Modulos\Financeiro\Emprestimos\RepositorioEmprestimos;
use App\Modulos\Financeiro\Planejamento\CalculadoraAgendaRecorrente;
use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ProjecaoCompromissosProximoMes
{
    public function __construct(
        private readonly RepositorioEmprestimos $emprestimos,
        private readonly ClassificadorParcelas $classificador,
        private readonly CalculadoraAgendaRecorrente $agenda,
    ) {}

    public function calcular(int $usuario, string $moeda, string $fimPeriodo): array
    {
        $inicio = CarbonImmutable::parse($fimPeriodo)->startOfMonth()->addMonth();
        $fim = $inicio->endOfMonth();
        $parcelas = $this->parcelasEmprestimos($usuario, $moeda, $inicio, $fim);
        $recorrentes = $this->pagamentosRecorrentes($usuario, $moeda, $inicio, $fim);
        $lancamentos = $this->lancamentosFuturos($usuario, $moeda, $inicio, $fim);
        $itens = array_merge($parcelas, $recorrentes, $lancamentos);
        usort($itens, fn (array $a, array $b): int => [$a['data'], $a['descricao']] <=> [$b['data'], $b['descricao']]);

        return [
            'inicio' => $inicio->toDateString(), 'fim' => $fim->toDateString(), 'moeda' => $moeda,
            'parcelas_emprestimos' => $this->somar($parcelas),
            'pagamentos_recorrentes' => $this->somar($recorrentes),
            'lancamentos_futuros' => $this->somar($lancamentos),
            'total' => $this->somar($itens),
            'itens' => $itens,
        ];
    }

    /** @return list<array{tipo: string, descricao: string, data: string, valor: string}> */
    private function parcelasEmprestimos(int $usuario, string $moeda, CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $contratos = $this->emprestimos->ativos($usuario, $moeda);
        $parcelas = $this->emprestimos->parcelasDe($contratos);
        $itens = [];
        foreach ($contratos as $contrato) {
            $devidas = $this->classificador->pendentes($contrato, $parcelas->get($contrato->id, collect()))
                ->whereBetween('vencimento', [$inicio->toDateString(), $fim->toDateString()]);
            foreach ($devidas as $parcela) {
                $itens[] = [
                    'tipo' => 'parcela_emprestimo',
                    'descricao' => "Parcela {$parcela->numero}/{$contrato->quantidade_parcelas}: {$contrato->credor}",
                    'data' => $parcela->vencimento,
                    'valor' => Entrada::moeda((string) $parcela->valor_total),
                ];
            }
        }

        return $itens;
    }

    /** @return list<array{tipo: string, descricao: string, data: string, valor: string}> */
    private function pagamentosRecorrentes(int $usuario, string $moeda, CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $pagamentos = DB::table('pagamentos_recorrentes')->where('usuario_id', $usuario)
            ->where('moeda', $moeda)->where('situacao', 'ativo')->get();
        $itens = [];
        foreach ($pagamentos as $pagamento) {
            $datas = $this->agenda->datasNoPeriodo($pagamento, $inicio->toDateString(), $fim->toDateString());
            foreach ($datas as $data) {
                $itens[] = [
                    'tipo' => 'pagamento_recorrente', 'descricao' => $pagamento->descricao,
                    'data' => $data, 'valor' => Entrada::moeda((string) $pagamento->valor),
                ];
            }
        }

        return $itens;
    }

    /** @return list<array{tipo: string, descricao: string, data: string, valor: string}> */
    private function lancamentosFuturos(int $usuario, string $moeda, CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        return DB::table('transacoes')->where('usuario_id', $usuario)->where('moeda', $moeda)
            ->where('situacao', 'confirmada')->where('valor', '<', 0)
            ->whereIn('natureza', ['saida', 'compra_cartao', 'pagamento_emprestimo'])
            ->whereBetween('data_efetiva', [$inicio->toDateString(), $fim->toDateString()])
            ->orderBy('data_efetiva')->orderBy('id')->get()
            ->map(fn (object $transacao): array => [
                'tipo' => 'lancamento_futuro', 'descricao' => $transacao->descricao_original,
                'data' => $transacao->data_efetiva,
                'valor' => Entrada::moeda(bcsub('0', (string) $transacao->valor, 2)),
            ])->all();
    }

    /** @param list<array{valor: string}> $itens */
    private function somar(array $itens): string
    {
        return Entrada::moeda(array_reduce(
            $itens,
            fn (string $total, array $item): string => bcadd($total, $item['valor'], 2),
            '0.00',
        ));
    }
}
