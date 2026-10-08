<?php

namespace App\Modulos\Financeiro\Controladores;

use App\Http\Controllers\Controller;
use App\Modulos\Financeiro\Importacao\LeitorExtrato;
use App\Modulos\Financeiro\Importacao\ServicoImportacao;
use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use App\Modulos\Financeiro\Suporte\IdentidadeTransacao as Identidade;
use App\Modulos\Financeiro\Suporte\IdentificadorPublico;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ControladorImportacoes extends Controller
{
    public function criar(Request $requisicao, LeitorExtrato $leitor): JsonResponse
    {
        $dados = Entrada::validar($requisicao, [
            'conta_id' => ['required', 'integer', 'min:1'],
            'instituicao' => ['nullable', 'string', 'max:120'],
            'arquivo' => ['required', 'file', 'max:5120'],
        ]);
        $usuario = Entrada::usuario($requisicao);
        $conta = Entrada::conta($usuario, (int) $dados['conta_id']);
        abort_unless($conta->situacao === 'ativa', 409);
        $arquivo = $dados['arquivo'];
        $formato = mb_strtolower((string) $arquivo->getClientOriginalExtension());
        if (! in_array($formato, ['ofx', 'csv', 'pdf'], true)) {
            throw ValidationException::withMessages(['arquivo' => 'ARQUIVO_NAO_SUPORTADO']);
        }
        $conteudo = file_get_contents($arquivo->getRealPath());
        if ($conteudo === false || $conteudo === '') {
            throw ValidationException::withMessages(['arquivo' => 'Arquivo vazio ou ilegível.']);
        }
        $hash = hash('sha256', $conteudo);
        $extraido = $leitor->ler($conteudo, $formato);
        unset($conteudo);
        $linhas = $extraido['linhas'];
        $datas = array_values(array_filter(array_column($linhas, 'data_efetiva')));
        $id = DB::transaction(function () use ($usuario, $conta, $dados, $arquivo, $formato, $hash, $extraido, $linhas, $datas): int {
            $id = DB::table('lotes_importacao')->insertGetId([
                'uuid' => IdentificadorPublico::novo(),
                'usuario_id' => $usuario, 'conta_id' => $conta->id,
                'instituicao' => trim($dados['instituicao'] ?? $extraido['instituicao'] ?? '') ?: null,
                'formato' => $formato,
                'nome_arquivo' => mb_substr(basename($arquivo->getClientOriginalName()), 0, 180),
                'hash_arquivo' => $hash, 'adaptador' => $extraido['adaptador'],
                'versao_adaptador' => $extraido['versao'], 'situacao' => 'previa',
                'periodo_inicio' => $datas === [] ? null : min($datas),
                'periodo_fim' => $datas === [] ? null : max($datas),
                'linhas_lidas' => count($linhas),
                'linhas_invalidas' => count(array_filter($linhas, fn ($linha) => $linha['erro'] !== null)),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (array_chunk($linhas, 500) as $parte) {
                DB::table('linhas_importacao')->insert(array_map(function (array $linha) use ($id, $usuario, $conta): array {
                    $valida = $linha['erro'] === null;

                    return [
                        'uuid' => IdentificadorPublico::novo(),
                        'lote_id' => $id, 'numero' => $linha['numero'],
                        'data_efetiva' => $linha['data_efetiva'], 'valor' => $linha['valor'],
                        'descricao_original' => $linha['descricao_original'],
                        'descricao_normalizada' => $valida ? Identidade::descricao($linha['descricao_original']) : null,
                        'identificador_bancario' => $linha['identificador_bancario'],
                        'documento' => $linha['documento'],
                        'fingerprint' => $valida ? Identidade::fingerprint($usuario, $conta->id, $linha['data_efetiva'], $linha['valor'], $conta->moeda, $linha['documento'], $linha['descricao_original']) : null,
                        'erro' => $linha['erro'],
                    ];
                }, $parte));
            }
            Entrada::auditar($usuario, 'lote_preparado', 'lotes_importacao', $id, ['linhas' => count($linhas)]);

            return $id;
        });

        return response()->json(['id' => $id, 'situacao' => 'previa', 'linhas_lidas' => count($linhas)], 201);
    }

    public function listar(Request $requisicao): JsonResponse
    {
        return response()->json(['dados' => DB::table('lotes_importacao')
            ->where('usuario_id', Entrada::usuario($requisicao))->orderByDesc('id')->limit(100)->get()]);
    }

    public function previa(Request $requisicao, int $id, ServicoImportacao $servico): JsonResponse
    {
        return response()->json($servico->previa(Entrada::usuario($requisicao), $id));
    }

    public function corrigirLinha(Request $requisicao, int $id, int $linhaId): JsonResponse
    {
        $dados = Entrada::validar($requisicao, [
            'data' => ['required', 'date_format:Y-m-d'],
            'valor' => Entrada::valorValido(),
            'descricao' => ['required', 'string', 'min:2', 'max:500'],
            'identificador_bancario' => ['nullable', 'string', 'max:150'],
            'documento' => ['nullable', 'string', 'max:120'],
        ]);
        if (bccomp($dados['valor'], '0', 2) === 0) {
            throw ValidationException::withMessages(['valor' => 'O valor não pode ser zero.']);
        }
        $usuario = Entrada::usuario($requisicao);
        $linha = DB::transaction(function () use ($usuario, $id, $linhaId, $dados): object {
            $lote = DB::table('lotes_importacao')->where('usuario_id', $usuario)->where('id', $id)->lockForUpdate()->first();
            abort_if($lote === null, 404);
            abort_unless($lote->situacao === 'previa', 409);
            $conta = Entrada::conta($usuario, $lote->conta_id);
            $anterior = DB::table('linhas_importacao')->where('lote_id', $id)->where('id', $linhaId)->first();
            abort_if($anterior === null, 404);
            $descricao = trim($dados['descricao']);
            $valor = Entrada::moeda($dados['valor']);
            DB::table('linhas_importacao')->where('id', $linhaId)->update([
                'data_efetiva' => $dados['data'], 'valor' => $valor,
                'descricao_original' => $descricao,
                'descricao_normalizada' => Identidade::descricao($descricao),
                'identificador_bancario' => $dados['identificador_bancario'] ?? null,
                'documento' => $dados['documento'] ?? null,
                'fingerprint' => Identidade::fingerprint($usuario, $conta->id, $dados['data'], $valor, $conta->moeda, $dados['documento'] ?? null, $descricao),
                'erro' => null,
            ]);
            $datas = DB::table('linhas_importacao')->where('lote_id', $id)->whereNotNull('data_efetiva');
            DB::table('lotes_importacao')->where('id', $id)->update([
                'periodo_inicio' => (clone $datas)->min('data_efetiva'),
                'periodo_fim' => $datas->max('data_efetiva'),
                'linhas_invalidas' => DB::table('linhas_importacao')->where('lote_id', $id)->whereNotNull('erro')->count(),
                'updated_at' => now(),
            ]);
            Entrada::auditar($usuario, 'linha_corrigida', 'linhas_importacao', $linhaId, ['numero' => $anterior->numero]);

            return DB::table('linhas_importacao')->find($linhaId);
        });

        return response()->json($linha);
    }

    public function cancelar(Request $requisicao, int $id): JsonResponse
    {
        $usuario = Entrada::usuario($requisicao);
        DB::transaction(function () use ($usuario, $id): void {
            $lote = DB::table('lotes_importacao')->where('usuario_id', $usuario)->where('id', $id)->lockForUpdate()->first();
            abort_if($lote === null, 404);
            abort_unless($lote->situacao === 'previa', 409);
            DB::table('lotes_importacao')->where('id', $id)->update(['situacao' => 'cancelado', 'updated_at' => now()]);
            Entrada::auditar($usuario, 'lote_cancelado', 'lotes_importacao', $id);
        });

        return response()->json(['id' => $id, 'situacao' => 'cancelado']);
    }

    public function confirmar(Request $requisicao, int $id, ServicoImportacao $servico): JsonResponse
    {
        $dados = Entrada::validar($requisicao, [
            'instituicao' => ['required', 'string', 'min:2', 'max:120'],
            'decisoes' => ['sometimes', 'array', 'max:5000'],
            'decisoes.*' => ['required', 'array:linha_id,decisao,transacao_candidata_id,categoria_id,motivo'],
            'decisoes.*.linha_id' => ['required', 'integer', 'min:1'],
            'decisoes.*.decisao' => ['required', 'in:importar,vincular,separada,ignorar'],
            'decisoes.*.transacao_candidata_id' => ['nullable', 'integer', 'min:1'],
            'decisoes.*.categoria_id' => ['nullable', 'integer', 'min:1'],
            'decisoes.*.motivo' => ['nullable', 'string', 'max:300'],
        ]);

        return response()->json($servico->confirmar(Entrada::usuario($requisicao), $id, $dados));
    }

    public function reverter(Request $requisicao, int $id, ServicoImportacao $servico): JsonResponse
    {
        $dados = Entrada::validar($requisicao, [
            'confirmar' => ['required', 'accepted'],
            'motivo' => ['required', 'string', 'min:5', 'max:300'],
        ]);

        return response()->json($servico->reverter(Entrada::usuario($requisicao), $id, trim($dados['motivo'])));
    }
}
