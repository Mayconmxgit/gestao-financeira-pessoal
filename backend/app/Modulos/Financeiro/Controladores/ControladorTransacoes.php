<?php

namespace App\Modulos\Financeiro\Controladores;

use App\Http\Controllers\Controller;
use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use App\Modulos\Financeiro\Suporte\IdentidadeTransacao as Identidade;
use App\Modulos\Financeiro\Suporte\IdentificadorPublico;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ControladorTransacoes extends Controller
{
    public function listar(Request $requisicao): JsonResponse
    {
        $dados = $this->validarFiltros($requisicao);
        $usuario = Entrada::usuario($requisicao);
        $consulta = DB::table('transacoes')->where('usuario_id', $usuario);
        $this->aplicarFiltros($consulta, $dados, $usuario);
        $limite = (int) ($dados['limite'] ?? 50);
        $ordem = $dados['ordem'] ?? 'asc';
        if (isset($dados['apos'])) {
            $this->aplicarCursor($consulta, $usuario, (int) $dados['apos'], $ordem);
        }
        $pagina = $consulta->orderBy('data_efetiva', $ordem)->orderBy('id', $ordem)
            ->limit($limite + 1)->get();
        $this->marcarEditaveis($pagina);

        return response()->json([
            'dados' => $pagina->take($limite)->values(),
            'proximo_cursor' => $pagina->count() > $limite ? $pagina[$limite - 1]->id : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function validarFiltros(Request $requisicao): array
    {
        return Entrada::validar($requisicao, [
            'conta_id' => ['sometimes', 'integer', 'min:1'],
            'categoria_id' => ['sometimes', 'integer', 'min:1'],
            'sem_categoria' => ['sometimes', 'boolean'],
            'natureza' => ['sometimes', 'in:entrada,saida,compra_cartao,transferencia,pagamento_fatura,estorno,ajuste,emprestimo_recebido,pagamento_emprestimo'],
            'origem' => ['sometimes', 'in:manual,importacao,conciliacao,emprestimo'],
            'busca' => ['sometimes', 'string', 'min:1', 'max:100'],
            'inicio' => ['sometimes', 'date_format:Y-m-d'],
            'fim' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:inicio'],
            'limite' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'apos' => ['sometimes', 'integer', 'min:0'],
            'ordem' => ['sometimes', 'in:asc,desc'],
            'moeda' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
        ]);
    }

    private function aplicarCursor(Builder $consulta, int $usuario, int $cursorId, string $ordem): void
    {
        $cursor = DB::table('transacoes')->where('usuario_id', $usuario)->where('id', $cursorId)->first();
        abort_if($cursor === null, 404);
        $operador = $ordem === 'desc' ? '<' : '>';
        $consulta->where(function (Builder $pagina) use ($cursor, $operador): void {
            $pagina->where('data_efetiva', $operador, $cursor->data_efetiva)
                ->orWhere(function (Builder $empate) use ($cursor, $operador): void {
                    $empate->where('data_efetiva', $cursor->data_efetiva)
                        ->where('id', $operador, $cursor->id);
                });
        });
    }

    /** @param array<string, mixed> $dados */
    private function aplicarFiltros(Builder $consulta, array $dados, int $usuario): void
    {
        if (isset($dados['categoria_id']) && ($dados['sem_categoria'] ?? false)) {
            throw ValidationException::withMessages(['categoria_id' => 'Escolha uma categoria ou movimentações sem categoria.']);
        }
        if (isset($dados['conta_id'])) {
            Entrada::conta($usuario, (int) $dados['conta_id']);
            $consulta->where('conta_id', $dados['conta_id']);
        }
        if (isset($dados['categoria_id'])) {
            Entrada::categoria($usuario, (int) $dados['categoria_id']);
            $consulta->where('categoria_id', $dados['categoria_id']);
        } elseif ($dados['sem_categoria'] ?? false) {
            $consulta->whereNull('categoria_id');
        }
        foreach (['moeda', 'natureza', 'origem'] as $campo) {
            if (isset($dados[$campo])) {
                $consulta->where($campo, $dados[$campo]);
            }
        }
        foreach (['inicio' => '>=', 'fim' => '<='] as $campo => $operador) {
            if (isset($dados[$campo])) {
                $consulta->where('data_efetiva', $operador, $dados[$campo]);
            }
        }
        if (isset($dados['busca'])) {
            $busca = trim((string) $dados['busca']);
            if ($busca === '') {
                throw ValidationException::withMessages(['busca' => 'Informe um texto para pesquisar.']);
            }
            $consulta->where('descricao_original', 'like', '%'.addcslashes($busca, '\\%_').'%');
        }
    }

    public function criar(Request $requisicao): JsonResponse
    {
        $dados = $this->validarMovimentacao($requisicao);
        $usuario = Entrada::usuario($requisicao);
        [$conta, $valor, $descricao] = $this->prepararMovimentacao($usuario, $dados);
        $id = DB::transaction(function () use ($usuario, $conta, $dados, $valor, $descricao): int {
            $registro = $this->dadosPersistencia($usuario, $conta, $dados, $valor, $descricao, null) + [
                'uuid' => IdentificadorPublico::novo(),
                'usuario_id' => $usuario, 'situacao' => 'confirmada', 'origem' => 'manual',
                'created_at' => now(), 'updated_at' => now(),
            ];
            $id = DB::table('transacoes')->insertGetId($registro);
            Entrada::auditar($usuario, 'transacao_criada', 'transacoes', $id);

            return $id;
        });

        return response()->json(DB::table('transacoes')->find($id), 201);
    }

    public function editar(Request $requisicao, int $id): JsonResponse
    {
        $dados = $this->validarMovimentacao($requisicao);
        $usuario = Entrada::usuario($requisicao);
        DB::transaction(function () use ($usuario, $id, $dados): void {
            $transacao = DB::table('transacoes')->where('usuario_id', $usuario)
                ->where('id', $id)->lockForUpdate()->first();
            abort_if($transacao === null, 404);
            $this->assegurarEdicaoPermitida($transacao);
            [$conta, $valor, $descricao] = $this->prepararMovimentacao($usuario, $dados, $transacao);
            $novos = $this->dadosPersistencia($usuario, $conta, $dados, $valor, $descricao, $transacao->documento);
            DB::table('transacoes')->where('id', $id)->update($novos + ['updated_at' => now()]);
            Entrada::auditar($usuario, 'transacao_editada', 'transacoes', $id, [
                'antes' => $this->dadosAuditaveis($transacao),
                'depois' => $this->dadosAuditaveis((object) ($novos + ['id' => $id])),
            ]);
        });

        return response()->json(DB::table('transacoes')->find($id));
    }

    /** @return array<string, mixed> */
    private function validarMovimentacao(Request $requisicao): array
    {
        return Entrada::validar($requisicao, [
            'conta_id' => ['required', 'integer', 'min:1'],
            'data' => ['required', 'date_format:Y-m-d'],
            'valor' => Entrada::valorValido(),
            'descricao' => ['required', 'string', 'min:2', 'max:500'],
            'natureza' => ['required', 'in:entrada,saida,compra_cartao'],
            'categoria_id' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    /** @param array<string, mixed> $dados
     * @return array{object, string, string}
     */
    private function prepararMovimentacao(int $usuario, array $dados, ?object $atual = null): array
    {
        $conta = Entrada::conta($usuario, (int) $dados['conta_id']);
        $mudouConta = $atual === null || $conta->id !== $atual->conta_id;
        abort_if($atual !== null && $atual->origem !== 'manual' && $mudouConta, 409);
        abort_unless(! $mudouConta || $conta->situacao === 'ativa', 409);
        $valor = Entrada::moeda($dados['valor']);
        $this->validarNaturezaEValor($conta, $dados['natureza'], $valor);
        $this->validarCategoria($usuario, $dados['categoria_id'] ?? null, $dados['natureza']);

        return [$conta, $valor, trim($dados['descricao'])];
    }

    private function validarNaturezaEValor(object $conta, string $natureza, string $valor): void
    {
        $entrada = $natureza === 'entrada';
        if (bccomp($valor, '0', 2) === 0 || ($entrada && bccomp($valor, '0', 2) < 0) || (! $entrada && bccomp($valor, '0', 2) > 0)) {
            throw ValidationException::withMessages(['valor' => 'Sinal do valor incompatível com a natureza.']);
        }
        if (($natureza === 'compra_cartao') !== ($conta->tipo === 'cartao_credito')) {
            throw ValidationException::withMessages(['natureza' => 'Natureza incompatível com o tipo da conta.']);
        }
    }

    private function validarCategoria(int $usuario, ?int $categoria, string $natureza): void
    {
        Entrada::categoria($usuario, $categoria);
        if ($categoria === null) {
            return;
        }
        $tipo = DB::table('categorias')->where('id', $categoria)->value('tipo');
        if ($tipo !== ($natureza === 'entrada' ? 'receita' : 'despesa')) {
            throw ValidationException::withMessages(['categoria_id' => 'Categoria incompatível com a movimentação.']);
        }
    }

    private function assegurarEdicaoPermitida(object $transacao): void
    {
        $simples = $transacao->situacao === 'confirmada'
            && in_array($transacao->natureza, ['entrada', 'saida', 'compra_cartao'], true)
            && $transacao->transacao_relacionada_id === null;
        $referenciada = DB::table('transacoes')->where('transacao_relacionada_id', $transacao->id)->exists();
        abort_unless($simples && ! $referenciada, 409);
    }

    /** @param array<string, mixed> $dados
     * @return array<string, mixed>
     */
    private function dadosPersistencia(int $usuario, object $conta, array $dados, string $valor, string $descricao, ?string $documento): array
    {
        return [
            'conta_id' => $conta->id, 'categoria_id' => $dados['categoria_id'] ?? null,
            'data_efetiva' => $dados['data'], 'valor' => $valor, 'moeda' => $conta->moeda,
            'descricao_original' => $descricao, 'descricao_normalizada' => Identidade::descricao($descricao),
            'fingerprint' => Identidade::fingerprint($usuario, $conta->id, $dados['data'], $valor, $conta->moeda, $documento, $descricao),
            'natureza' => $dados['natureza'],
        ];
    }

    /** @return array<string, mixed> */
    private function dadosAuditaveis(object $transacao): array
    {
        return [
            'conta_id' => $transacao->conta_id, 'categoria_id' => $transacao->categoria_id ?? null,
            'data' => $transacao->data_efetiva, 'valor' => $transacao->valor,
            'descricao' => $transacao->descricao_original, 'natureza' => $transacao->natureza,
        ];
    }

    private function marcarEditaveis(Collection $pagina): void
    {
        $ids = $pagina->pluck('id');
        $referenciadas = DB::table('transacoes')->whereIn('transacao_relacionada_id', $ids)
            ->pluck('transacao_relacionada_id')->flip();
        foreach ($pagina as $transacao) {
            $transacao->editavel = $transacao->situacao === 'confirmada'
                && in_array($transacao->natureza, ['entrada', 'saida', 'compra_cartao'], true)
                && $transacao->transacao_relacionada_id === null
                && ! $referenciadas->has($transacao->id);
        }
    }

    public function classificar(Request $requisicao, int $id): JsonResponse
    {
        $dados = Entrada::validar($requisicao, ['categoria_id' => ['nullable', 'integer', 'min:1']]);
        $usuario = Entrada::usuario($requisicao);
        $transacao = DB::table('transacoes')->where('usuario_id', $usuario)->where('id', $id)->first();
        abort_if($transacao === null, 404);
        abort_unless($transacao->situacao === 'confirmada', 409);
        Entrada::categoria($usuario, $dados['categoria_id'] ?? null);
        if (isset($dados['categoria_id'])) {
            $tipo = DB::table('categorias')->where('id', $dados['categoria_id'])->value('tipo');
            $esperado = bccomp((string) $transacao->valor, '0', 2) > 0 ? 'receita' : 'despesa';
            if ($tipo !== $esperado || in_array($transacao->natureza, ['transferencia', 'pagamento_fatura', 'ajuste'], true)) {
                throw ValidationException::withMessages(['categoria_id' => 'Categoria incompatível com a movimentação.']);
            }
        }
        DB::transaction(function () use ($usuario, $id, $dados, $transacao): void {
            DB::table('transacoes')->where('id', $id)->update([
                'categoria_id' => $dados['categoria_id'] ?? null, 'updated_at' => now(),
            ]);
            Entrada::auditar($usuario, 'transacao_classificada', 'transacoes', $id, [
                'categoria_anterior' => $transacao->categoria_id,
                'categoria_nova' => $dados['categoria_id'] ?? null,
            ]);
        });

        return response()->json(DB::table('transacoes')->find($id));
    }

    public function transferir(Request $requisicao): JsonResponse
    {
        $dados = Entrada::validar($requisicao, [
            'origem_id' => ['required', 'integer', 'min:1'],
            'destino_id' => ['required', 'integer', 'min:1', 'different:origem_id'],
            'data' => ['required', 'date_format:Y-m-d'],
            'valor' => ['required', 'regex:/^[0-9]{1,15}(?:\.[0-9]{1,2})?$/'],
            'descricao' => ['required', 'string', 'min:2', 'max:500'],
        ]);
        $usuario = Entrada::usuario($requisicao);
        $origem = Entrada::conta($usuario, (int) $dados['origem_id']);
        $destino = Entrada::conta($usuario, (int) $dados['destino_id']);
        abort_unless($origem->situacao === 'ativa' && $destino->situacao === 'ativa', 409);
        if ($origem->moeda !== $destino->moeda || bccomp($dados['valor'], '0', 2) <= 0 || $origem->tipo === 'cartao_credito') {
            throw ValidationException::withMessages(['valor' => 'Transferência exige valor positivo e contas ativas da mesma moeda.']);
        }
        $ids = DB::transaction(function () use ($usuario, $origem, $destino, $dados): array {
            $natureza = $destino->tipo === 'cartao_credito' ? 'pagamento_fatura' : 'transferencia';
            $valor = Entrada::moeda($dados['valor']);
            $ids = [];
            foreach ([[$origem, bcsub('0', $valor, 2)], [$destino, $valor]] as [$conta, $valorLado]) {
                $ids[] = DB::table('transacoes')->insertGetId([
                    'uuid' => IdentificadorPublico::novo(),
                    'usuario_id' => $usuario, 'conta_id' => $conta->id,
                    'data_efetiva' => $dados['data'], 'valor' => $valorLado,
                    'moeda' => $conta->moeda, 'descricao_original' => trim($dados['descricao']),
                    'descricao_normalizada' => Identidade::descricao($dados['descricao']),
                    'fingerprint' => Identidade::fingerprint($usuario, $conta->id, $dados['data'], $valorLado, $conta->moeda, null, $dados['descricao']),
                    'natureza' => $natureza, 'situacao' => 'confirmada', 'origem' => 'manual',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('transacoes')->where('id', $ids[0])->update(['transacao_relacionada_id' => $ids[1]]);
            DB::table('transacoes')->where('id', $ids[1])->update(['transacao_relacionada_id' => $ids[0]]);
            Entrada::auditar($usuario, 'transferencia_criada', 'transacoes', $ids[0], ['contrapartida' => $ids[1]]);

            return $ids;
        });

        return response()->json(['origem' => DB::table('transacoes')->find($ids[0]), 'destino' => DB::table('transacoes')->find($ids[1])], 201);
    }

    public function estornar(Request $requisicao, int $id): JsonResponse
    {
        $dados = Entrada::validar($requisicao, [
            'data' => ['required', 'date_format:Y-m-d'],
            'motivo' => ['required', 'string', 'min:5', 'max:300'],
        ]);
        $usuario = Entrada::usuario($requisicao);
        $original = DB::table('transacoes')->where('usuario_id', $usuario)->where('id', $id)->first();
        abort_if($original === null, 404);
        abort_unless($original->situacao === 'confirmada' && in_array($original->natureza, ['entrada', 'saida', 'compra_cartao'], true), 409);
        abort_if($dados['data'] < $original->data_efetiva, 422);
        $estorno = DB::transaction(function () use ($usuario, $id, $dados): int {
            $original = DB::table('transacoes')->where('usuario_id', $usuario)->where('id', $id)->lockForUpdate()->first();
            abort_unless($original !== null && $original->situacao === 'confirmada', 409);
            abort_if(DB::table('transacoes')->where('transacao_relacionada_id', $id)->where('natureza', 'estorno')->exists(), 409);
            $valor = bcsub('0', (string) $original->valor, 2);
            $descricao = trim($dados['motivo']);
            $novo = DB::table('transacoes')->insertGetId([
                'uuid' => IdentificadorPublico::novo(),
                'usuario_id' => $usuario, 'conta_id' => $original->conta_id,
                'categoria_id' => $original->categoria_id,
                'transacao_relacionada_id' => $id,
                'data_efetiva' => $dados['data'], 'valor' => $valor,
                'moeda' => $original->moeda, 'descricao_original' => $descricao,
                'descricao_normalizada' => Identidade::descricao($descricao),
                'fingerprint' => Identidade::fingerprint($usuario, $original->conta_id, $dados['data'], $valor, $original->moeda, null, $descricao),
                'natureza' => 'estorno', 'situacao' => 'confirmada', 'origem' => 'manual',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Entrada::auditar($usuario, 'transacao_estornada', 'transacoes', $id, ['estorno_id' => $novo, 'motivo' => $descricao]);

            return $novo;
        });

        return response()->json(DB::table('transacoes')->find($estorno), 201);
    }

    public function vincularContrapartida(Request $requisicao, int $id): JsonResponse
    {
        $dados = Entrada::validar($requisicao, [
            'conta_contrapartida_id' => ['required', 'integer', 'min:1'],
            'natureza' => ['required', 'in:transferencia,pagamento_fatura'],
        ]);
        $usuario = Entrada::usuario($requisicao);
        $resultado = DB::transaction(function () use ($usuario, $id, $dados): int {
            $original = DB::table('transacoes')->where('usuario_id', $usuario)->where('id', $id)->lockForUpdate()->first();
            abort_if($original === null, 404);
            abort_unless($original->situacao === 'confirmada' && in_array($original->natureza, ['entrada', 'saida'], true) && $original->transacao_relacionada_id === null, 409);
            $conta = Entrada::conta($usuario, $original->conta_id);
            $outra = Entrada::conta($usuario, (int) $dados['conta_contrapartida_id']);
            abort_unless($outra->id !== $conta->id && $outra->situacao === 'ativa' && $outra->moeda === $conta->moeda, 422);
            $cartoes = (int) ($conta->tipo === 'cartao_credito') + (int) ($outra->tipo === 'cartao_credito');
            if (($dados['natureza'] === 'pagamento_fatura' && $cartoes !== 1) || ($dados['natureza'] === 'transferencia' && $cartoes !== 0)) {
                throw ValidationException::withMessages(['natureza' => 'Contas incompatíveis com a natureza.']);
            }
            $valor = bcsub('0', (string) $original->valor, 2);
            if ($dados['natureza'] === 'pagamento_fatura' && (($conta->tipo === 'cartao_credito' && bccomp((string) $original->valor, '0', 2) <= 0) || ($outra->tipo === 'cartao_credito' && bccomp((string) $original->valor, '0', 2) >= 0))) {
                throw ValidationException::withMessages(['natureza' => 'Pagamento exige saída da conta pagadora e entrada no cartão.']);
            }
            $novo = DB::table('transacoes')->insertGetId([
                'uuid' => IdentificadorPublico::novo(),
                'usuario_id' => $usuario, 'conta_id' => $outra->id,
                'transacao_relacionada_id' => $id,
                'data_efetiva' => $original->data_efetiva, 'valor' => $valor,
                'moeda' => $outra->moeda, 'descricao_original' => $original->descricao_original,
                'descricao_normalizada' => $original->descricao_normalizada,
                'fingerprint' => Identidade::fingerprint($usuario, $outra->id, $original->data_efetiva, $valor, $outra->moeda, null, $original->descricao_original),
                'natureza' => $dados['natureza'], 'situacao' => 'confirmada', 'origem' => 'conciliacao',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('transacoes')->where('id', $id)->update([
                'natureza' => $dados['natureza'], 'categoria_id' => null,
                'transacao_relacionada_id' => $novo, 'updated_at' => now(),
            ]);
            Entrada::auditar($usuario, 'contrapartida_vinculada', 'transacoes', $id, ['contrapartida' => $novo, 'categoria_anterior' => $original->categoria_id]);

            return $novo;
        });

        return response()->json(['original' => DB::table('transacoes')->find($id), 'contrapartida' => DB::table('transacoes')->find($resultado)], 201);
    }
}
