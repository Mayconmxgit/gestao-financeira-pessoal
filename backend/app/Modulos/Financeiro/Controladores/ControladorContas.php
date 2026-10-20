<?php

namespace App\Modulos\Financeiro\Controladores;

use App\Http\Controllers\Controller;
use App\Modulos\Financeiro\Cartoes\ServicoFaturasCartao;
use App\Modulos\Financeiro\Suporte\CodigoMovimentacao as Codigo;
use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use App\Modulos\Financeiro\Suporte\IdentificadorPublico;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ControladorContas extends Controller
{
    public function __construct(private readonly ServicoFaturasCartao $faturas) {}

    public function listar(Request $requisicao): JsonResponse
    {
        $usuario = Entrada::usuario($requisicao);
        $contas = DB::table('contas as conta')->leftJoin('contas as banco', 'banco.id', '=', 'conta.conta_pagamento_id')
            ->where('conta.usuario_id', $usuario)
            ->select('conta.id', 'conta.nome', 'conta.tipo', 'conta.moeda', 'conta.saldo_inicial',
                DB::raw('COALESCE(conta.instituicao, banco.instituicao) as instituicao'), 'conta.situacao',
                'conta.conta_pagamento_id', 'banco.nome as conta_pagamento_nome', 'conta.limite_credito', 'conta.dia_fechamento', 'conta.dia_vencimento')
            ->orderBy('conta.id')->get();
        $contas->transform(function (object $conta) use ($usuario): object {
            if ($conta->tipo === 'cartao_credito') {
                foreach ($this->faturas->indicadores($usuario, (int) $conta->id) as $chave => $valor) {
                    $conta->{$chave} = $valor;
                }
            }

            return $conta;
        });

        return response()->json(['dados' => $contas]);
    }

    public function criar(Request $requisicao): JsonResponse
    {
        $dados = Entrada::validar($requisicao, [
            'nome' => ['required', 'string', 'min:2', 'max:120'],
            'tipo' => ['required', 'in:corrente,poupanca,cartao_credito,dinheiro,outro'],
            'moeda' => ['sometimes', 'regex:/^[A-Z]{3}$/'],
            'saldo_inicial' => ['sometimes', 'regex:/^-?[0-9]{1,15}(?:\.[0-9]{1,2})?$/'],
            'instituicao' => ['nullable', 'string', 'max:120'],
            'conta_pagamento_id' => ['nullable', 'integer', 'min:1'],
            'limite_credito' => ['nullable', 'regex:/^[0-9]{1,15}(?:\.[0-9]{1,2})?$/'],
            'dia_fechamento' => ['nullable', 'integer', 'between:1,31'],
            'dia_vencimento' => ['nullable', 'integer', 'between:1,31'],
        ]);
        $usuario = Entrada::usuario($requisicao);
        $cartao = $dados['tipo'] === 'cartao_credito';
        if ($cartao) {
            if (array_key_exists('saldo_inicial', $dados)) {
                throw ValidationException::withMessages(['saldo_inicial' => 'Saldo inicial não se aplica a cartão de crédito.']);
            }
            foreach (['limite_credito', 'dia_fechamento', 'dia_vencimento'] as $campo) {
                if (! isset($dados[$campo])) {
                    throw ValidationException::withMessages([$campo => 'Campo obrigatório para cartão de crédito.']);
                }
            }
            if (isset($dados['conta_pagamento_id'])) {
                $contaPagamento = Entrada::conta($usuario, (int) $dados['conta_pagamento_id']);
                abort_unless($contaPagamento->tipo !== 'cartao_credito' && $contaPagamento->situacao === 'ativa'
                    && (! isset($dados['moeda']) || $contaPagamento->moeda === $dados['moeda']), 422);
                $dados['moeda'] ??= $contaPagamento->moeda;
            } elseif (! isset($dados['moeda'])) {
                throw ValidationException::withMessages(['moeda' => 'Campo obrigatório para cartão sem conta vinculada.']);
            }
            abort_unless(bccomp((string) $dados['limite_credito'], '0', 2) > 0, 422);
            $dados['saldo_inicial'] = '0.00';
        } else {
            if (array_intersect(array_keys($dados), ['conta_pagamento_id', 'limite_credito', 'dia_fechamento', 'dia_vencimento'])) {
                throw ValidationException::withMessages(['campos' => 'Configuração de fatura permitida somente para cartões.']);
            }
            if (! isset($dados['moeda'], $dados['saldo_inicial'])) {
                throw ValidationException::withMessages(['campos' => 'Moeda e saldo inicial são obrigatórios para contas bancárias.']);
            }
        }
        $id = DB::transaction(function () use ($dados, $usuario, $cartao): int {
            $id = DB::table('contas')->insertGetId([
                'uuid' => IdentificadorPublico::novo(),
                'usuario_id' => $usuario,
                'nome' => trim($dados['nome']),
                'tipo' => $dados['tipo'],
                'moeda' => $dados['moeda'],
                'saldo_inicial' => Entrada::moeda($dados['saldo_inicial']),
                // Mantido apenas para compatibilidade com o esquema existente; não limita lançamentos.
                'data_saldo' => now()->toDateString(),
                'instituicao' => isset($dados['instituicao']) ? trim($dados['instituicao']) : null,
                'conta_pagamento_id' => $cartao ? ($dados['conta_pagamento_id'] ?? null) : null,
                'limite_credito' => $cartao ? Entrada::moeda($dados['limite_credito']) : null,
                'dia_fechamento' => $cartao ? $dados['dia_fechamento'] : null,
                'dia_vencimento' => $cartao ? $dados['dia_vencimento'] : null,
                'situacao' => 'ativa',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Entrada::auditar($usuario, 'conta_criada', 'contas', $id);
            if ($cartao) {
                $this->faturas->iniciar($usuario, $id);
            }

            return $id;
        });

        return response()->json($this->buscarContaPublica($id), 201);
    }

    public function atualizar(Request $requisicao, int $id): JsonResponse
    {
        $usuario = Entrada::usuario($requisicao);
        Entrada::conta($usuario, $id);
        $dados = Entrada::validar($requisicao, [
            'nome' => ['sometimes', 'string', 'min:2', 'max:120'],
            'instituicao' => ['sometimes', 'nullable', 'string', 'max:120'],
            'situacao' => ['sometimes', 'in:ativa,arquivada'],
            'conta_pagamento_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'limite_credito' => ['sometimes', 'regex:/^[0-9]{1,15}(?:\.[0-9]{1,2})?$/'],
            'dia_fechamento' => ['sometimes', 'integer', 'between:1,31'],
            'dia_vencimento' => ['sometimes', 'integer', 'between:1,31'],
        ]);
        if ($dados === []) {
            throw ValidationException::withMessages(['campos' => 'Informe ao menos uma alteração.']);
        }
        $contaAtual = Entrada::conta($usuario, $id);
        if ($contaAtual->tipo !== 'cartao_credito' && array_intersect(array_keys($dados), ['conta_pagamento_id', 'limite_credito', 'dia_fechamento', 'dia_vencimento'])) {
            throw ValidationException::withMessages(['campos' => 'Configuração de fatura permitida somente para cartões.']);
        }
        if ($contaAtual->tipo === 'cartao_credito') {
            if (isset($dados['conta_pagamento_id'])) {
                $pagadora = Entrada::conta($usuario, (int) $dados['conta_pagamento_id']);
                abort_unless($pagadora->tipo !== 'cartao_credito' && $pagadora->situacao === 'ativa' && $pagadora->moeda === $contaAtual->moeda, 422);
            }
            if (isset($dados['limite_credito'])) {
                abort_unless(bccomp($dados['limite_credito'], '0', 2) > 0, 422);
                $dados['limite_credito'] = Entrada::moeda($dados['limite_credito']);
                $this->faturas->validarNovoLimite($usuario, $id, $dados['limite_credito']);
            }
            if (array_key_exists('instituicao', $dados) && $dados['instituicao'] !== null) {
                $dados['instituicao'] = trim($dados['instituicao']);
            }
        }
        if (($dados['situacao'] ?? null) === 'arquivada') {
            if ($contaAtual->tipo === 'cartao_credito') {
                abort_if(DB::table('faturas_cartao')->where('cartao_id', $id)->whereIn('situacao', ['aberta', 'fechada', 'vencida'])
                    ->where('valor_total', '>', 0)->exists(), 409, 'Pague as faturas pendentes antes de arquivar o cartão.');
            } else {
                abort_if(DB::table('contas')->where('conta_pagamento_id', $id)->where('situacao', 'ativa')->exists(), 409,
                    'Arquive ou vincule os cartões a outra conta antes de arquivar esta conta.');
            }
        }
        DB::transaction(function () use ($usuario, $id, $dados, $contaAtual): void {
            DB::table('contas')->where('id', $id)->where('usuario_id', $usuario)
                ->update([...$dados, 'updated_at' => now()]);
            if ($contaAtual->tipo === 'cartao_credito' && array_key_exists('conta_pagamento_id', $dados)) {
                DB::table('faturas_cartao')->where('cartao_id', $id)->where('situacao', 'aberta')
                    ->update(['conta_pagamento_id' => $dados['conta_pagamento_id'], 'updated_at' => now()]);
            }
            Entrada::auditar($usuario, 'conta_atualizada', 'contas', $id, ['campos' => array_keys($dados)]);
        });

        return response()->json($this->buscarContaPublica($id));
    }

    public function ajustar(Request $requisicao, int $id): JsonResponse
    {
        $usuario = Entrada::usuario($requisicao);
        $conta = Entrada::conta($usuario, $id);
        abort_unless($conta->situacao === 'ativa', 409);
        $dados = Entrada::validar($requisicao, [
            'data' => ['required', 'date_format:Y-m-d'],
            'valor' => Entrada::valorValido(),
            'motivo' => ['required', 'string', 'min:5', 'max:300'],
        ]);
        if (bccomp($dados['valor'], '0', 2) === 0) {
            throw ValidationException::withMessages(['valor' => 'O ajuste não pode ser zero.']);
        }
        $transacao = DB::transaction(function () use ($usuario, $conta, $dados): int {
            $descricao = trim($dados['motivo']);
            $id = DB::table('transacoes')->insertGetId([
                'uuid' => IdentificadorPublico::novo(), 'codigo' => Codigo::novo($usuario),
                'usuario_id' => $usuario, 'conta_id' => $conta->id,
                'data_efetiva' => $dados['data'], 'valor' => Entrada::moeda($dados['valor']),
                'moeda' => $conta->moeda, 'descricao_original' => $descricao,
                'descricao_normalizada' => mb_strtolower($descricao),
                'fingerprint' => hash('sha256', implode('|', [$usuario, $conta->id, $dados['data'], $dados['valor'], $descricao])),
                'natureza' => 'ajuste', 'situacao' => 'confirmada', 'origem' => 'manual',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Entrada::auditar($usuario, 'saldo_ajustado', 'transacoes', $id, ['motivo' => $descricao]);

            return $id;
        });

        return response()->json(DB::table('transacoes')->find($transacao), 201);
    }

    private function buscarContaPublica(int $id): object
    {
        return DB::table('contas as conta')->leftJoin('contas as banco', 'banco.id', '=', 'conta.conta_pagamento_id')
            ->where('conta.id', $id)
            ->select('conta.id', 'conta.nome', 'conta.tipo', 'conta.moeda', 'conta.saldo_inicial',
                DB::raw('COALESCE(conta.instituicao, banco.instituicao) as instituicao'), 'conta.situacao',
                'conta.conta_pagamento_id', 'banco.nome as conta_pagamento_nome', 'conta.limite_credito', 'conta.dia_fechamento', 'conta.dia_vencimento')
            ->firstOrFail();
    }
}
