<?php

namespace App\Modulos\Financeiro\Controladores;

use App\Http\Controllers\Controller;
use App\Modulos\Financeiro\Emprestimos\ConsultaEmprestimos;
use App\Modulos\Financeiro\Emprestimos\ServicoEmprestimos;
use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ControladorEmprestimos extends Controller
{
    public function __construct(
        private readonly ServicoEmprestimos $servico,
        private readonly ConsultaEmprestimos $consulta,
    ) {}

    public function listar(Request $requisicao): JsonResponse
    {
        return response()->json(['dados' => $this->consulta->listar(Entrada::usuario($requisicao))]);
    }

    public function mostrar(Request $requisicao, int $id): JsonResponse
    {
        return response()->json($this->consulta->buscar(Entrada::usuario($requisicao), $id));
    }

    public function cronograma(Request $requisicao, int $id): JsonResponse
    {
        $dados = $this->consulta->buscar(Entrada::usuario($requisicao), $id);

        return response()->json(['emprestimo_id' => $id, 'parcelas' => $dados['parcelas'], 'aviso_projecao' => $dados['aviso_projecao']]);
    }

    public function criar(Request $requisicao): JsonResponse
    {
        $dados = $this->validarEmprestimo($requisicao);
        $usuario = Entrada::usuario($requisicao);
        $conta = $this->contaRecebimento($usuario, (int) $dados['conta_recebimento_id']);
        $id = $this->servico->criar($usuario, $conta, $dados);

        return response()->json($this->consulta->buscar($usuario, $id), 201);
    }

    public function atualizar(Request $requisicao, int $id): JsonResponse
    {
        $dados = $this->validarEmprestimo($requisicao);
        $usuario = Entrada::usuario($requisicao);
        $conta = $this->contaRecebimento($usuario, (int) $dados['conta_recebimento_id']);
        $this->servico->atualizar($usuario, $id, $conta, $dados);

        return response()->json($this->consulta->buscar($usuario, $id));
    }

    public function excluir(Request $requisicao, int $id): JsonResponse
    {
        $dados = Entrada::validar($requisicao, ['confirmar' => ['required', 'accepted']]);
        $this->servico->excluir(Entrada::usuario($requisicao), $id);

        return response()->json(['id' => $id, 'excluido' => (bool) $dados['confirmar']]);
    }

    public function pagarParcela(Request $requisicao, int $id, int $parcelaId): JsonResponse
    {
        $dados = Entrada::validar($requisicao, [
            'conta_pagamento_id' => ['nullable', 'required_unless:afetar_saldo,false', 'integer', 'min:1'],
            'data_pagamento' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'afetar_saldo' => ['sometimes', 'boolean'],
        ]);
        $usuario = Entrada::usuario($requisicao);
        $conta = isset($dados['conta_pagamento_id']) ? Entrada::conta($usuario, (int) $dados['conta_pagamento_id']) : null;
        $this->servico->pagarParcela($usuario, $id, $parcelaId, $conta, $dados);

        return response()->json($this->consulta->buscar($usuario, $id));
    }

    public function desfazerPagamentoParcela(Request $requisicao, int $id, int $parcelaId): JsonResponse
    {
        $usuario = Entrada::usuario($requisicao);
        $this->servico->desfazerPagamentoParcela($usuario, $id, $parcelaId);

        return response()->json($this->consulta->buscar($usuario, $id));
    }

    public function associarTransacao(Request $requisicao, int $id, int $parcelaId): JsonResponse
    {
        $dados = Entrada::validar($requisicao, ['transacao_id' => ['required', 'integer', 'min:1']]);
        $usuario = Entrada::usuario($requisicao);
        $this->servico->associarTransacao($usuario, $id, $parcelaId, (int) $dados['transacao_id']);

        return response()->json($this->consulta->buscar($usuario, $id));
    }

    public function simularQuitacao(Request $requisicao, int $id): JsonResponse
    {
        $dados = Entrada::validar($requisicao, ['valor_quitacao' => ['nullable', ...$this->valorNaoNegativo(false)]]);

        return response()->json($this->consulta->simularQuitacao(
            Entrada::usuario($requisicao),
            $id,
            $dados['valor_quitacao'] ?? null,
        ));
    }

    public function quitar(Request $requisicao, int $id): JsonResponse
    {
        $dados = Entrada::validar($requisicao, [
            'conta_pagamento_id' => ['required', 'integer', 'min:1'],
            'data_pagamento' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'juros' => $this->valorNaoNegativo(),
            'taxas' => $this->valorNaoNegativo(),
        ]);
        $usuario = Entrada::usuario($requisicao);
        $conta = Entrada::conta($usuario, (int) $dados['conta_pagamento_id']);
        $this->servico->quitar($usuario, $id, $conta, $dados);

        return response()->json($this->consulta->buscar($usuario, $id));
    }

    private function validarEmprestimo(Request $requisicao): array
    {
        return Entrada::validar($requisicao, [
            'conta_recebimento_id' => ['required', 'integer', 'min:1'],
            'credor' => ['required', 'string', 'min:2', 'max:160'],
            'descricao' => ['nullable', 'string', 'max:300'],
            'tipo_divida' => ['sometimes', 'in:emprestimo_pessoal,emprestimo_consignado,financiamento,parcelamento,renegociacao'],
            'valor_principal' => $this->valorPositivo(),
            'valor_recebido' => ['sometimes', ...$this->valorPositivo(false)],
            'valor_financiado' => ['sometimes', ...$this->valorPositivo(false)],
            'iof' => ['sometimes', ...$this->valorNaoNegativo(false)],
            'taxas_totais' => ['sometimes', ...$this->valorNaoNegativo(false)],
            'taxa_juros_mensal' => $this->taxa(true),
            'taxa_juros_anual' => $this->taxa(false),
            'cet_mensal' => $this->taxa(false),
            'cet_anual' => $this->taxa(false),
            'sistema_amortizacao' => ['required', 'in:price,sac,manual'],
            'quantidade_parcelas' => ['required', 'integer', 'min:1', 'max:600'],
            'valor_parcela_contratada' => ['nullable', ...$this->valorPositivo(false)],
            'total_contratado' => ['nullable', ...$this->valorPositivo(false)],
            'valor_total_manual' => ['nullable', 'required_if:sistema_amortizacao,manual', 'regex:/^[0-9]{1,15}(?:\.[0-9]{1,2})?$/'],
            'data_contratacao' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'data_recebimento' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'data_base_calculo' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:primeiro_vencimento'],
            'primeiro_vencimento' => ['required', 'date_format:Y-m-d', 'after_or_equal:data_recebimento'],
            'afetar_saldo_recebimento' => ['sometimes', 'boolean'],
        ]);
    }

    private function contaRecebimento(int $usuario, int $id): object
    {
        $conta = Entrada::conta($usuario, $id);
        abort_unless($conta->situacao === 'ativa' && $conta->tipo !== 'cartao_credito', 422);

        return $conta;
    }

    private function valorPositivo(bool $obrigatorio = true): array
    {
        return [$obrigatorio ? 'required' : 'nullable', 'regex:/^[0-9]{1,15}(?:\.[0-9]{1,2})?$/', 'not_in:0,0.0,0.00'];
    }

    private function valorNaoNegativo(bool $obrigatorio = true): array
    {
        return [$obrigatorio ? 'required' : 'nullable', 'regex:/^[0-9]{1,15}(?:\.[0-9]{1,2})?$/'];
    }

    private function taxa(bool $obrigatoria): array
    {
        return [$obrigatoria ? 'required' : 'nullable', 'regex:/^[0-9]{1,3}(?:\.[0-9]{1,6})?$/', 'numeric', 'min:0', 'max:999.999999'];
    }
}
