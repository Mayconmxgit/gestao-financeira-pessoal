<?php

namespace App\Modulos\Financeiro\Controladores;

use App\Http\Controllers\Controller;
use App\Modulos\Financeiro\Planejamento\ServicoAportesMetas;
use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use App\Modulos\Financeiro\Suporte\IdentificadorPublico;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ControladorMetasFinanceiras extends Controller
{
    public function listar(Request $requisicao): JsonResponse
    {
        return response()->json(['dados' => $this->consultar(Entrada::usuario($requisicao))]);
    }

    public function criar(Request $requisicao): JsonResponse
    {
        $usuario = Entrada::usuario($requisicao);
        $dados = Entrada::validar($requisicao, $this->regras(false));
        $this->validarValor($dados['valor_objetivo']);
        $id = DB::transaction(function () use ($usuario, $dados): int {
            $id = DB::table('metas_financeiras')->insertGetId([
                'uuid' => IdentificadorPublico::novo(),
                'usuario_id' => $usuario, 'nome' => trim($dados['nome']),
                'valor_objetivo' => Entrada::moeda($dados['valor_objetivo']), 'moeda' => 'BRL',
                'prazo' => $dados['prazo'] ?? null, 'situacao' => 'ativa', 'created_at' => now(), 'updated_at' => now(),
            ]);
            Entrada::auditar($usuario, 'meta_financeira_criada', 'metas_financeiras', $id);

            return $id;
        });

        return response()->json($this->encontrar($usuario, $id), 201);
    }

    public function atualizar(Request $requisicao, int $id): JsonResponse
    {
        $usuario = Entrada::usuario($requisicao);
        abort_if(! DB::table('metas_financeiras')->where('usuario_id', $usuario)->where('id', $id)->exists(), 404);
        $dados = Entrada::validar($requisicao, $this->regras(true));
        $this->validarValor($dados['valor_objetivo']);
        DB::transaction(function () use ($usuario, $id, $dados): void {
            $acumulado = $this->totalAportes($usuario, $id);
            $situacao = bccomp($acumulado, $dados['valor_objetivo'], 2) >= 0 ? 'concluida' : $dados['situacao'];
            DB::table('metas_financeiras')->where('usuario_id', $usuario)->where('id', $id)->update([
                'nome' => trim($dados['nome']), 'valor_objetivo' => Entrada::moeda($dados['valor_objetivo']),
                'prazo' => $dados['prazo'] ?? null, 'situacao' => $situacao, 'updated_at' => now(),
            ]);
            Entrada::auditar($usuario, 'meta_financeira_editada', 'metas_financeiras', $id);
        });

        return response()->json($this->encontrar($usuario, $id));
    }

    public function aportar(Request $requisicao, int $id, ServicoAportesMetas $servico): JsonResponse
    {
        $usuario = Entrada::usuario($requisicao);
        $dados = Entrada::validar($requisicao, [
            'conta_id' => ['required', 'integer', 'min:1'],
            'valor' => Entrada::valorValido(), 'data' => ['required', 'date_format:Y-m-d'],
            'observacao' => ['nullable', 'string', 'max:300'],
        ]);
        $this->validarValor($dados['valor']);
        $servico->registrar($usuario, $id, $dados);

        return response()->json(['meta' => $this->encontrar($usuario, $id)], 201);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function consultar(int $usuario): Collection
    {
        $metas = DB::table('metas_financeiras')->where('usuario_id', $usuario)
            ->orderByRaw("CASE situacao WHEN 'ativa' THEN 0 WHEN 'pausada' THEN 1 ELSE 2 END")
            ->orderBy('prazo')->orderByDesc('id')->get();
        $aportes = DB::table('aportes_metas as aporte')
            ->leftJoin('contas as conta', 'conta.id', '=', 'aporte.conta_id')
            ->where('aporte.usuario_id', $usuario)->whereIn('aporte.meta_id', $metas->pluck('id'))
            ->select('aporte.*', 'conta.nome as conta_nome', 'conta.instituicao as conta_instituicao')
            ->orderByDesc('aporte.data')->orderByDesc('aporte.id')->get()->groupBy('meta_id');

        return $metas->map(fn (object $meta): array => $this->apresentar($meta, $aportes->get($meta->id, collect())))->values();
    }

    /** @param Collection<int, object> $aportes
     * @return array<string, mixed>
     */
    private function apresentar(object $meta, Collection $aportes): array
    {
        $acumulado = $aportes->reduce(fn (string $total, object $aporte): string => bcadd($total, (string) $aporte->valor, 2), '0.00');
        $percentual = bcdiv(bcmul($acumulado, '100', 4), (string) $meta->valor_objetivo, 2);

        return (array) $meta + ['valor_acumulado' => $acumulado, 'percentual_concluido' => $percentual, 'aportes' => $aportes->values()];
    }

    /** @return array<string, mixed> */
    private function encontrar(int $usuario, int $id): array
    {
        return $this->consultar($usuario)->firstWhere('id', $id) ?? abort(404);
    }

    private function totalAportes(int $usuario, int $id): string
    {
        return Entrada::moeda((string) DB::table('aportes_metas')->where('usuario_id', $usuario)->where('meta_id', $id)->sum('valor'));
    }

    private function validarValor(string $valor): void
    {
        if (bccomp($valor, '0', 2) <= 0) {
            throw ValidationException::withMessages(['valor' => 'O valor deve ser maior que zero.']);
        }
    }

    /** @return array<string, mixed> */
    private function regras(bool $edicao): array
    {
        $regras = [
            'nome' => ['required', 'string', 'min:2', 'max:120'],
            'valor_objetivo' => Entrada::valorValido(), 'prazo' => ['nullable', 'date_format:Y-m-d'],
        ];

        return $edicao ? $regras + ['situacao' => ['required', 'in:ativa,pausada']] : $regras;
    }
}
