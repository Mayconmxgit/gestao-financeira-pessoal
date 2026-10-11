<?php

namespace App\Modulos\Financeiro\Controladores;

use App\Http\Controllers\Controller;
use App\Modulos\Financeiro\Planejamento\ServicoPagamentosRecorrentes;
use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use App\Modulos\Financeiro\Suporte\IdentificadorPublico;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ControladorPagamentosRecorrentes extends Controller
{
    public function listar(Request $requisicao): JsonResponse
    {
        $usuario = Entrada::usuario($requisicao);
        $dados = DB::table('pagamentos_recorrentes as pagamento')
            ->join('contas as conta', 'conta.id', '=', 'pagamento.conta_id')
            ->leftJoin('categorias as categoria', 'categoria.id', '=', 'pagamento.categoria_id')
            ->where('pagamento.usuario_id', $usuario)
            ->select('pagamento.*', 'conta.nome as conta_nome', 'categoria.nome as categoria_nome')
            ->selectRaw("CASE WHEN conta.tipo = 'cartao_credito' THEN 'cartao_credito' ELSE 'debito' END as forma_pagamento")
            ->orderByRaw("CASE pagamento.situacao WHEN 'ativo' THEN 0 WHEN 'pausado' THEN 1 ELSE 2 END")
            ->orderBy('pagamento.proximo_vencimento')->get()->values();
        $dados->each(fn (object $pagamento) => $pagamento->cobranca_automatica = (bool) $pagamento->cobranca_automatica);

        return response()->json(['dados' => $dados]);
    }

    public function criar(Request $requisicao): JsonResponse
    {
        $usuario = Entrada::usuario($requisicao);
        $dados = Entrada::validar($requisicao, $this->regrasCriacao());
        $registro = $this->preparar($usuario, $dados, $dados['primeiro_vencimento']);
        $id = DB::transaction(function () use ($usuario, $registro): int {
            $id = DB::table('pagamentos_recorrentes')->insertGetId($registro + [
                'uuid' => IdentificadorPublico::novo(),
                'usuario_id' => $usuario, 'primeiro_vencimento' => $registro['proximo_vencimento'],
                'situacao' => 'ativo', 'created_at' => now(), 'updated_at' => now(),
            ]);
            Entrada::auditar($usuario, 'pagamento_recorrente_criado', 'pagamentos_recorrentes', $id);

            return $id;
        });

        return response()->json($this->buscar($id), 201);
    }

    public function atualizar(Request $requisicao, int $id): JsonResponse
    {
        $usuario = Entrada::usuario($requisicao);
        $atual = DB::table('pagamentos_recorrentes')->where('usuario_id', $usuario)->where('id', $id)->first();
        abort_if($atual === null, 404);
        $dados = Entrada::validar($requisicao, $this->regrasEdicao());
        $registro = $this->preparar($usuario, $dados, $dados['proximo_vencimento']);
        $primeiroVencimento = $dados['proximo_vencimento'] === $atual->proximo_vencimento
            ? $atual->primeiro_vencimento : $dados['proximo_vencimento'];
        DB::transaction(function () use ($usuario, $id, $dados, $registro, $primeiroVencimento): void {
            DB::table('pagamentos_recorrentes')->where('usuario_id', $usuario)->where('id', $id)
                ->update($registro + ['primeiro_vencimento' => $primeiroVencimento, 'situacao' => $dados['situacao'], 'updated_at' => now()]);
            Entrada::auditar($usuario, 'pagamento_recorrente_editado', 'pagamentos_recorrentes', $id);
        });

        return response()->json($this->buscar($id));
    }

    public function pagar(Request $requisicao, int $id, ServicoPagamentosRecorrentes $servico): JsonResponse
    {
        $dados = Entrada::validar($requisicao, ['data' => ['required', 'date_format:Y-m-d']]);

        return response()->json($servico->pagar(Entrada::usuario($requisicao), $id, $dados['data']), 201);
    }

    /** @return array<string, mixed> */
    private function preparar(int $usuario, array $dados, string $vencimento): array
    {
        $conta = Entrada::conta($usuario, (int) $dados['conta_id']);
        if ($conta->moeda !== 'BRL') {
            throw ValidationException::withMessages(['conta_id' => 'Selecione uma conta em reais.']);
        }
        abort_unless($conta->situacao === 'ativa', 409);
        $formaPagamento = $conta->tipo === 'cartao_credito' ? 'cartao_credito' : 'debito';
        if (isset($dados['forma_pagamento']) && $dados['forma_pagamento'] !== $formaPagamento) {
            throw ValidationException::withMessages(['conta_id' => 'Selecione uma conta compatível com a forma de pagamento.']);
        }
        if (($dados['cobranca_automatica'] ?? false) && $formaPagamento !== 'cartao_credito') {
            throw ValidationException::withMessages(['cobranca_automatica' => 'A cobrança automática está disponível apenas para cartão de crédito.']);
        }
        Entrada::categoria($usuario, $dados['categoria_id'] ?? null);
        if (($dados['categoria_id'] ?? null) !== null && DB::table('categorias')->where('id', $dados['categoria_id'])->value('tipo') !== 'despesa') {
            throw ValidationException::withMessages(['categoria_id' => 'Use uma categoria de despesa.']);
        }
        if (bccomp($dados['valor'], '0', 2) <= 0) {
            throw ValidationException::withMessages(['valor' => 'O valor deve ser maior que zero.']);
        }

        return [
            'conta_id' => $conta->id, 'categoria_id' => $dados['categoria_id'] ?? null,
            'descricao' => trim($dados['descricao']), 'valor' => Entrada::moeda($dados['valor']), 'moeda' => 'BRL',
            'periodicidade' => $dados['periodicidade'], 'proximo_vencimento' => $vencimento, 'data_fim' => $dados['data_fim'] ?? null,
            'cobranca_automatica' => (bool) ($dados['cobranca_automatica'] ?? false),
        ];
    }

    /** @return array<string, mixed> */
    private function regrasCriacao(): array
    {
        return $this->regrasComuns() + [
            'primeiro_vencimento' => ['required', 'date_format:Y-m-d'],
            'data_fim' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:primeiro_vencimento'],
        ];
    }

    /** @return array<string, mixed> */
    private function regrasEdicao(): array
    {
        return $this->regrasComuns() + [
            'proximo_vencimento' => ['required', 'date_format:Y-m-d'],
            'data_fim' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:proximo_vencimento'],
            'situacao' => ['required', 'in:ativo,pausado'],
        ];
    }

    /** @return array<string, mixed> */
    private function regrasComuns(): array
    {
        return [
            'conta_id' => ['required', 'integer', 'min:1'], 'categoria_id' => ['nullable', 'integer', 'min:1'],
            'descricao' => ['required', 'string', 'min:2', 'max:500'], 'valor' => Entrada::valorValido(),
            'periodicidade' => ['required', 'in:semanal,mensal,anual'],
            'forma_pagamento' => ['sometimes', 'in:debito,cartao_credito'],
            'cobranca_automatica' => ['sometimes', 'boolean'],
        ];
    }

    private function buscar(int $id): object
    {
        $pagamento = DB::table('pagamentos_recorrentes as pagamento')
            ->join('contas as conta', 'conta.id', '=', 'pagamento.conta_id')
            ->where('pagamento.id', $id)
            ->select('pagamento.*')
            ->selectRaw("CASE WHEN conta.tipo = 'cartao_credito' THEN 'cartao_credito' ELSE 'debito' END as forma_pagamento")
            ->first();
        $pagamento->cobranca_automatica = (bool) $pagamento->cobranca_automatica;

        return $pagamento;
    }
}
