<?php

namespace App\Modulos\Financeiro\Emprestimos;

use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use Illuminate\Support\Collection;

final class ConsultaEmprestimos
{
    public function __construct(
        private readonly RepositorioEmprestimos $repositorio,
        private readonly CalculadoraCustoCredito $calculadoraCusto,
        private readonly SimuladorQuitacao $simuladorQuitacao,
        private readonly ClassificadorParcelas $classificador,
    ) {}

    public function listar(int $usuario): array
    {
        $emprestimos = $this->repositorio->listar($usuario);
        $parcelas = $this->repositorio->parcelasDe($emprestimos);

        return $emprestimos->map(fn (object $emprestimo) => $this->detalhar(
            $emprestimo,
            false,
            $parcelas->get($emprestimo->id, collect()),
        ))->all();
    }

    public function buscar(int $usuario, int $id, bool $comParcelas = true): array
    {
        $emprestimo = $this->repositorio->buscar($usuario, $id);
        abort_if($emprestimo === null, 404);

        return $this->detalhar($emprestimo, $comParcelas);
    }

    public function simularQuitacao(int $usuario, int $id, ?string $valor): array
    {
        $emprestimo = $this->repositorio->buscar($usuario, $id);
        abort_if($emprestimo === null, 404);
        $parcelas = $this->repositorio->parcelas($id);
        $pendentes = $this->classificador->pendentes($emprestimo, $parcelas)->values()->all();

        return $this->simuladorQuitacao->simular($pendentes, $valor);
    }

    private function detalhar(object $emprestimo, bool $comParcelas, ?Collection $parcelas = null): array
    {
        $parcelas ??= $this->repositorio->parcelas($emprestimo->id);
        $pendentes = $this->classificador->pendentes($emprestimo, $parcelas);
        $naoAplicaveis = $parcelas->filter(fn (object $parcela) => $this->classificador->naoSeAplica($emprestimo, $parcela));
        $pagas = $parcelas->whereIn('situacao', ['paga', 'quitada_antecipadamente']);
        $somar = fn (string $campo, Collection $itens) => $itens->reduce(
            fn (string $total, object $item) => bcadd($total, (string) ($item->{$campo} ?? '0'), 2), '0.00'
        );
        $proxima = $pendentes->sortBy('vencimento')->first();
        $saldoDevedor = $somar('principal', $pendentes);
        $saldoFinanciado = bcadd($saldoDevedor, $somar('taxas', $pendentes), 2);
        $totalProjetado = $somar('valor_total', $parcelas);
        $totalPrevisto = $emprestimo->total_contratado ?? $totalProjetado;
        $valorRecebido = $emprestimo->valor_recebido ?? $emprestimo->valor_principal;
        $custos = $this->calculadoraCusto->calcular((string) $valorRecebido, (string) $totalPrevisto);
        $quantidadePaga = $pagas->count();
        $quantidadeAcompanhada = ((int) $emprestimo->quantidade_parcelas) - $naoAplicaveis->count();
        $situacaoExibicao = $emprestimo->situacao === 'ativo' && $pendentes->contains(
            fn (object $parcela) => $parcela->vencimento < now()->toDateString()
        ) ? 'atrasado' : $emprestimo->situacao;
        $dados = (array) $emprestimo + $custos + [
            'situacao_exibicao' => $situacaoExibicao,
            'afeta_saldo_recebimento' => $emprestimo->transacao_recebimento_id !== null,
            'valor_total' => $totalProjetado,
            'valor_total_projetado' => $totalProjetado,
            'saldo_devedor' => $saldoDevedor,
            'saldo_financiado' => $saldoFinanciado,
            'total_pendente' => $somar('valor_total', $pendentes),
            'total_pago' => $somar('valor_pago', $pagas),
            'juros_pagos' => $somar('juros_pago', $pagas),
            'amortizacao_acumulada' => $somar('amortizacao_paga', $pagas),
            'juros_futuros_estimados' => $somar('juros', $pendentes),
            'parcelas_pagas' => $quantidadePaga,
            'parcelas_pendentes' => $pendentes->count(),
            'parcelas_restantes' => $pendentes->count(),
            'parcelas_nao_aplicaveis' => $naoAplicaveis->count(),
            'parcelas_acompanhadas' => $quantidadeAcompanhada,
            'percentual_concluido' => $quantidadeAcompanhada > 0 ? bcdiv(bcmul((string) $quantidadePaga, '100', 4), (string) $quantidadeAcompanhada, 2) : '0.00',
            'proxima_parcela' => $proxima ? $this->parcela($emprestimo, $proxima) : null,
            'aviso_projecao' => 'Projeção estimada pelo Life Finance. O cálculo oficial da instituição financeira pode variar conforme datas e critérios contratuais.',
        ];
        foreach (['valor_principal', 'taxas_totais', 'valor_recebido', 'valor_financiado', 'iof', 'valor_parcela_contratada', 'total_contratado'] as $campo) {
            if (isset($dados[$campo])) {
                $dados[$campo] = Entrada::moeda((string) $dados[$campo]);
            }
        }
        if ($comParcelas) {
            $dados['parcelas'] = $parcelas->map(fn (object $parcela) => $this->parcela($emprestimo, $parcela))->values();
        }

        return $dados;
    }

    private function parcela(object $emprestimo, object $parcela): array
    {
        $dados = (array) $parcela;
        foreach (['saldo_inicial', 'principal', 'juros', 'taxas', 'valor_total', 'saldo_final', 'valor_pago', 'juros_pago', 'amortizacao_paga'] as $campo) {
            if (isset($dados[$campo])) {
                $dados[$campo] = Entrada::moeda((string) $dados[$campo]);
            }
        }
        $dados['amortizacao'] = bcadd((string) $parcela->principal, (string) $parcela->taxas, 2);
        $dados['situacao_exibicao'] = $this->classificador->situacaoExibicao($emprestimo, $parcela);

        return $dados;
    }
}
