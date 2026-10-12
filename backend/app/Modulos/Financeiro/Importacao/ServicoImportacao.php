<?php

namespace App\Modulos\Financeiro\Importacao;

use App\Modulos\Financeiro\Cartoes\ServicoFaturasCartao;
use App\Modulos\Financeiro\Suporte\CodigoMovimentacao as Codigo;
use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use App\Modulos\Financeiro\Suporte\IdentificadorPublico;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ServicoImportacao
{
    public function __construct(private readonly ServicoFaturasCartao $faturas) {}

    private function lote(int $usuario, int $id): object
    {
        $lote = DB::table('lotes_importacao')->where('usuario_id', $usuario)->where('id', $id)->first();
        abort_if($lote === null, 404);

        return $lote;
    }

    public function previa(int $usuario, int $id): array
    {
        $lote = $this->lote($usuario, $id);
        $linhas = DB::table('linhas_importacao')->where('lote_id', $id)->orderBy('numero')->get();
        $identificadores = $linhas->pluck('identificador_bancario')->filter()->unique()->values()->all();
        $fingerprints = $linhas->pluck('fingerprint')->filter()->unique()->values()->all();
        $porIdentificador = $this->candidatos($usuario, $lote->conta_id, 'identificador_bancario', $identificadores);
        $porFingerprint = $this->candidatos($usuario, $lote->conta_id, 'fingerprint', $fingerprints);
        $entradas = '0.00';
        $saidas = '0.00';
        $vistas = [];
        $fingerprintsVistos = [];
        $dados = [];
        foreach ($linhas as $linha) {
            $identificador = $linha->identificador_bancario;
            $estavel = $identificador ? ($porIdentificador[$identificador] ?? null) : null;
            $candidato = $linha->fingerprint ? ($porFingerprint[$linha->fingerprint] ?? null) : null;
            $repetidaNoLote = $identificador && isset($vistas[$identificador]);
            $fingerprintNoLote = $linha->fingerprint ? ($fingerprintsVistos[$linha->fingerprint] ?? null) : null;
            if ($identificador) {
                $vistas[$identificador] = true;
            }
            if ($linha->fingerprint) {
                $fingerprintsVistos[$linha->fingerprint] ??= $linha->id;
            }
            $linha->situacao_previa = $linha->erro ? 'invalida'
                : ($estavel || $repetidaNoLote ? 'duplicada_confirmada'
                    : ($candidato || $fingerprintNoLote ? 'possivel_duplicidade' : 'pronta'));
            $linha->transacao_candidata_id = $estavel?->id ?? $candidato?->id;
            $linha->linha_candidata_id = $fingerprintNoLote;
            if ($linha->erro === null && ! $estavel && ! $repetidaNoLote) {
                $valor = (string) $linha->valor;
                if (bccomp($valor, '0', 2) > 0) {
                    $entradas = bcadd($entradas, $valor, 2);
                } else {
                    $saidas = bcadd($saidas, bcsub('0', $valor, 2), 2);
                }
            }
            $dados[] = $linha;
        }

        return ['lote' => $lote, 'total_entradas' => $entradas, 'total_saidas' => $saidas, 'linhas' => $dados];
    }

    private function candidatos(int $usuario, int $conta, string $campo, array $valores): array
    {
        $mapa = [];
        foreach (array_chunk($valores, 500) as $parte) {
            $achados = DB::table('transacoes')->select('id', $campo)
                ->where('usuario_id', $usuario)->where('conta_id', $conta)
                ->where('situacao', 'confirmada')->whereIn($campo, $parte)->get();
            foreach ($achados as $achado) {
                $mapa[$achado->{$campo}] = $achado;
            }
        }

        return $mapa;
    }

    public function confirmar(int $usuario, int $id, array $dados): array
    {
        $decisoes = [];
        foreach ($dados['decisoes'] ?? [] as $decisao) {
            if (isset($decisoes[$decisao['linha_id']])) {
                throw ValidationException::withMessages(['decisoes' => 'Linha repetida nas decisões.']);
            }
            $decisoes[$decisao['linha_id']] = $decisao;
        }

        return DB::transaction(function () use ($usuario, $id, $dados, $decisoes): array {
            $lote = DB::table('lotes_importacao')->where('usuario_id', $usuario)->where('id', $id)->lockForUpdate()->first();
            abort_if($lote === null, 404);
            abort_unless($lote->situacao === 'previa', 409);
            $conta = DB::table('contas')->where('usuario_id', $usuario)->where('id', $lote->conta_id)->lockForUpdate()->first();
            abort_unless($conta !== null && $conta->situacao === 'ativa', 409);
            $linhas = DB::table('linhas_importacao')->where('lote_id', $id)->orderBy('numero')->get();
            $ids = $linhas->pluck('id')->all();
            if (array_diff(array_keys($decisoes), $ids)) {
                throw ValidationException::withMessages(['decisoes' => 'Decisão contém linha de outro lote.']);
            }
            $importadas = 0;
            $ignoradas = 0;
            $identificadores = [];
            foreach ($linhas as $linha) {
                $decisao = $decisoes[$linha->id] ?? null;
                if ($linha->erro !== null) {
                    if (($decisao['decisao'] ?? 'ignorar') !== 'ignorar') {
                        throw ValidationException::withMessages(['decisoes' => "LINHA_INVALIDA: linha $linha->numero"]);
                    }
                    $ignoradas++;

                    continue;
                }
                $estavel = $linha->identificador_bancario;
                $duplicada = $estavel && (isset($identificadores[$estavel]) || DB::table('transacoes')
                    ->where('conta_id', $conta->id)->where('identificador_bancario', $estavel)->exists());
                if ($estavel) {
                    $identificadores[$estavel] = true;
                }
                if ($duplicada) {
                    $ignoradas++;
                    $this->registrarDecisao($usuario, $linha->id, 'duplicada_confirmada', null, null, null);

                    continue;
                }
                $candidato = DB::table('transacoes')->where('usuario_id', $usuario)
                    ->where('conta_id', $conta->id)->where('fingerprint', $linha->fingerprint)
                    ->where('situacao', 'confirmada')->orderBy('id')->first();
                $escolha = $decisao['decisao'] ?? null;
                if ($candidato && ! in_array($escolha, ['vincular', 'separada', 'ignorar'], true)) {
                    throw ValidationException::withMessages(['decisoes' => "POSSIVEL_DUPLICIDADE: linha $linha->numero"]);
                }
                if ($escolha === 'vincular') {
                    if (! $candidato || (int) ($decisao['transacao_candidata_id'] ?? 0) !== $candidato->id) {
                        throw ValidationException::withMessages(['decisoes' => "Candidato inválido na linha $linha->numero"]);
                    }
                    $ignoradas++;
                    $this->registrarDecisao($usuario, $linha->id, 'vincular', $candidato->id, null, $decisao['motivo'] ?? null);

                    continue;
                }
                if ($escolha === 'ignorar') {
                    $ignoradas++;
                    $this->registrarDecisao($usuario, $linha->id, 'ignorar', $candidato?->id, null, $decisao['motivo'] ?? null);

                    continue;
                }
                if ($escolha !== null && ! in_array($escolha, ['importar', 'separada'], true)) {
                    throw ValidationException::withMessages(['decisoes' => "Decisão inválida na linha $linha->numero"]);
                }
                $categoria = $decisao['categoria_id'] ?? null;
                Entrada::categoria($usuario, $categoria);
                if ($categoria !== null) {
                    $tipo = DB::table('categorias')->where('id', $categoria)->value('tipo');
                    $esperado = bccomp((string) $linha->valor, '0', 2) > 0 ? 'receita' : 'despesa';
                    if ($tipo !== $esperado) {
                        throw ValidationException::withMessages(['categoria_id' => "Categoria incompatível na linha $linha->numero"]);
                    }
                }
                $natureza = bccomp((string) $linha->valor, '0', 2) > 0 ? 'entrada' : ($conta->tipo === 'cartao_credito' ? 'compra_cartao' : 'saida');
                $fatura = $natureza === 'compra_cartao'
                    ? $this->faturas->prepararCompra($usuario, (int) $conta->id, (string) $linha->valor, $linha->data_efetiva) : null;
                $transacao = DB::table('transacoes')->insertGetId([
                    'uuid' => IdentificadorPublico::novo(), 'codigo' => Codigo::novo($usuario),
                    'usuario_id' => $usuario, 'conta_id' => $conta->id, 'fatura_cartao_id' => $fatura, 'lote_id' => $id,
                    'categoria_id' => $categoria, 'data_efetiva' => $linha->data_efetiva,
                    'valor' => $linha->valor, 'moeda' => $conta->moeda,
                    'descricao_original' => $linha->descricao_original,
                    'descricao_normalizada' => $linha->descricao_normalizada,
                    'identificador_bancario' => $linha->identificador_bancario,
                    'documento' => $linha->documento, 'fingerprint' => $linha->fingerprint,
                    'natureza' => $natureza, 'situacao' => 'confirmada', 'origem' => 'importacao',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($fatura !== null) {
                    $this->faturas->sincronizar($fatura);
                }
                $importadas++;
                $this->registrarDecisao($usuario, $linha->id, $candidato ? 'separada' : 'importar', $candidato?->id, $transacao, $decisao['motivo'] ?? null);
            }
            DB::table('lotes_importacao')->where('id', $id)->update([
                'instituicao' => trim($dados['instituicao']), 'situacao' => 'confirmado',
                'linhas_importadas' => $importadas, 'linhas_ignoradas' => $ignoradas,
                'confirmado_em' => now(), 'updated_at' => now(),
            ]);
            DB::table('contas')->where('id', $conta->id)->update(['instituicao' => trim($dados['instituicao']), 'updated_at' => now()]);
            Entrada::auditar($usuario, 'lote_confirmado', 'lotes_importacao', $id, ['importadas' => $importadas, 'ignoradas' => $ignoradas]);

            return ['id' => $id, 'situacao' => 'confirmado', 'importadas' => $importadas, 'ignoradas' => $ignoradas];
        });
    }

    private function registrarDecisao(int $usuario, int $linha, string $decisao, ?int $candidata, ?int $criada, ?string $motivo): void
    {
        DB::table('decisoes_conciliacao')->insert([
            'usuario_id' => $usuario, 'linha_id' => $linha,
            'transacao_candidata_id' => $candidata, 'transacao_criada_id' => $criada,
            'decisao' => $decisao, 'motivo' => $motivo, 'criado_em' => now(),
        ]);
    }

    public function reverter(int $usuario, int $id, string $motivo): array
    {
        return DB::transaction(function () use ($usuario, $id, $motivo): array {
            $lote = DB::table('lotes_importacao')->where('usuario_id', $usuario)->where('id', $id)->lockForUpdate()->first();
            abort_if($lote === null, 404);
            abort_unless($lote->situacao === 'confirmado', 409);
            $ids = DB::table('transacoes')->where('usuario_id', $usuario)
                ->where('lote_id', $id)->pluck('id')->all();
            DB::table('transacoes')->where('usuario_id', $usuario)
                ->where('origem', 'conciliacao')->whereIn('transacao_relacionada_id', $ids)
                ->where('situacao', 'confirmada')->update(['situacao' => 'estornada', 'updated_at' => now()]);
            $quantidade = DB::table('transacoes')->where('usuario_id', $usuario)
                ->where('lote_id', $id)->where('situacao', 'confirmada')
                ->update(['situacao' => 'estornada', 'updated_at' => now()]);
            DB::table('lotes_importacao')->where('id', $id)->update([
                'situacao' => 'revertido', 'revertido_em' => now(), 'updated_at' => now(),
            ]);
            Entrada::auditar($usuario, 'lote_revertido', 'lotes_importacao', $id, ['motivo' => $motivo, 'transacoes' => $quantidade]);

            return ['id' => $id, 'situacao' => 'revertido', 'transacoes_revertidas' => $quantidade];
        });
    }
}
