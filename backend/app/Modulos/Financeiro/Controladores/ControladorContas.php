<?php

namespace App\Modulos\Financeiro\Controladores;

use App\Http\Controllers\Controller;
use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use App\Modulos\Financeiro\Suporte\IdentificadorPublico;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ControladorContas extends Controller
{
    public function listar(Request $requisicao): JsonResponse
    {
        $contas = DB::table('contas')->where('usuario_id', Entrada::usuario($requisicao))
            ->select('id', 'nome', 'tipo', 'moeda', 'saldo_inicial', 'instituicao', 'situacao')
            ->orderBy('id')->get();

        return response()->json(['dados' => $contas]);
    }

    public function criar(Request $requisicao): JsonResponse
    {
        $dados = Entrada::validar($requisicao, [
            'nome' => ['required', 'string', 'min:2', 'max:120'],
            'tipo' => ['required', 'in:corrente,poupanca,cartao_credito,dinheiro,outro'],
            'moeda' => ['required', 'regex:/^[A-Z]{3}$/'],
            'saldo_inicial' => Entrada::valorValido(),
            'instituicao' => ['nullable', 'string', 'max:120'],
        ]);
        $usuario = Entrada::usuario($requisicao);
        $id = DB::transaction(function () use ($dados, $usuario): int {
            $id = DB::table('contas')->insertGetId([
                'uuid' => IdentificadorPublico::novo(),
                'usuario_id' => $usuario,
                'nome' => trim($dados['nome']),
                'tipo' => $dados['tipo'],
                'moeda' => $dados['moeda'],
                'saldo_inicial' => Entrada::moeda($dados['saldo_inicial']),
                // Mantido apenas para compatibilidade com o esquema existente; não limita lançamentos.
                'data_saldo' => now()->toDateString(),
                'instituicao' => $dados['instituicao'] ?? null,
                'situacao' => 'ativa',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Entrada::auditar($usuario, 'conta_criada', 'contas', $id);

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
        ]);
        if ($dados === []) {
            throw ValidationException::withMessages(['campos' => 'Informe ao menos uma alteração.']);
        }
        DB::transaction(function () use ($usuario, $id, $dados): void {
            DB::table('contas')->where('id', $id)->where('usuario_id', $usuario)
                ->update([...$dados, 'updated_at' => now()]);
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
                'uuid' => IdentificadorPublico::novo(),
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
        return DB::table('contas')->where('id', $id)
            ->select('id', 'nome', 'tipo', 'moeda', 'saldo_inicial', 'instituicao', 'situacao')
            ->firstOrFail();
    }
}
