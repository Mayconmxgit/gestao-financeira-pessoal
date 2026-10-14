<?php

namespace App\Modulos\Assinaturas\Controladores;

use App\Http\Controllers\Controller;
use App\Modulos\Assinaturas\Servicos\ServicoAdministracaoAssinaturas;
use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ControladorAdministracaoAssinaturas extends Controller
{
    private const SITUACOES = ['criando', 'pendente', 'ativa', 'periodo_graca', 'inadimplente', 'pausada', 'cancelada', 'falha'];

    public function resumo(ServicoAdministracaoAssinaturas $servico): JsonResponse
    {
        return response()->json($servico->resumo());
    }

    public function planos(ServicoAdministracaoAssinaturas $servico): JsonResponse
    {
        return response()->json(['dados' => $servico->listarPlanos()]);
    }

    public function criarPlano(Request $requisicao, ServicoAdministracaoAssinaturas $servico): JsonResponse
    {
        $plano = $servico->criarPlano(Entrada::usuario($requisicao), Entrada::validar($requisicao, $this->regrasPlano()));

        return response()->json($plano, 201);
    }

    public function atualizarPlano(Request $requisicao, string $uuid, ServicoAdministracaoAssinaturas $servico): JsonResponse
    {
        $plano = $servico->atualizarPlano(Entrada::usuario($requisicao), $uuid, Entrada::validar($requisicao, $this->regrasPlano()));

        return response()->json($plano);
    }

    public function assinaturas(Request $requisicao, ServicoAdministracaoAssinaturas $servico): JsonResponse
    {
        $dados = $this->consulta($requisicao, permiteSituacao: true);

        return response()->json($servico->listarAssinaturas(
            $dados['apos'] ?? null, $dados['limite'] ?? 30, $dados['situacao'] ?? null, $dados['visao'] ?? 'atuais'
        ));
    }

    public function eventos(Request $requisicao, ServicoAdministracaoAssinaturas $servico): JsonResponse
    {
        $dados = $this->consulta($requisicao);

        return response()->json($servico->listarEventos($dados['apos'] ?? null, $dados['limite'] ?? 30));
    }

    public function reconciliar(Request $requisicao, string $uuid, ServicoAdministracaoAssinaturas $servico): JsonResponse
    {
        return response()->json($servico->reconciliar(Entrada::usuario($requisicao), $uuid));
    }

    public function cortesia(Request $requisicao, ServicoAdministracaoAssinaturas $servico): JsonResponse
    {
        $dados = Entrada::validar($requisicao, [
            'usuario_id' => ['required', 'uuid'], 'plano_id' => ['required', 'uuid'],
            'fim' => ['required', 'date', 'after:today'], 'motivo' => ['required', 'string', 'min:5', 'max:300'],
        ]);
        $usuario = DB::table('usuarios')->where('uuid', $dados['usuario_id'])->first();
        $plano = DB::table('planos_assinatura')->where('uuid', $dados['plano_id'])->where('ativo', true)->first();
        abort_if($usuario === null || $plano === null, 404);

        return response()->json($servico->concederCortesia(Entrada::usuario($requisicao), $usuario, $plano, $dados), 201);
    }

    private function regrasPlano(): array
    {
        return [
            'nome' => ['required', 'string', 'min:2', 'max:100'], 'descricao' => ['nullable', 'string', 'max:500'],
            'valor' => ['required', 'regex:/^[0-9]{1,8}(?:\.[0-9]{1,2})?$/'],
            'periodicidade' => ['required', Rule::in(['mensal', 'anual'])],
            'recursos' => ['required', 'array', 'min:1', 'max:30'], 'recursos.*' => ['required', 'string', 'min:2', 'max:100', 'distinct'],
            'ativo' => ['required', 'boolean'],
        ];
    }

    private function consulta(Request $requisicao, bool $permiteSituacao = false): array
    {
        $regras = ['apos' => ['nullable', 'integer', 'min:1'], 'limite' => ['nullable', 'integer', 'between:1,100']];
        if ($permiteSituacao) {
            $regras['situacao'] = ['nullable', Rule::in(self::SITUACOES)];
            $regras['visao'] = ['nullable', Rule::in(['atuais', 'historico'])];
        }
        if (array_diff(array_keys($requisicao->query()), array_keys($regras))) {
            throw ValidationException::withMessages(['campos' => 'A consulta contém campos não permitidos.']);
        }

        return Validator::make($requisicao->query(), $regras)->validate();
    }
}
