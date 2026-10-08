<?php

namespace App\Modulos\Financeiro\Emprestimos;

use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use App\Modulos\Financeiro\Suporte\IdentidadeTransacao as Identidade;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ServicoEmprestimos
{
    public function __construct(
        private readonly CalculadoraParcelas $calculadora,
        private readonly RepositorioEmprestimos $repositorio,
        private readonly ClassificadorParcelas $classificador,
    ) {}

    public function criar(int $usuario, object $conta, array $dados): int
    {
        return DB::transaction(function () use ($usuario, $conta, $dados): int {
            $agora = now();
            $dados = $this->normalizarContrato($dados);
            $emprestimo = $this->repositorio->criar($this->dadosPersistencia($usuario, $conta, $dados, $agora));
            $transacao = ($dados['afetar_saldo_recebimento'] ?? true)
                ? $this->registrarRecebimento($usuario, $conta, $emprestimo, $dados)
                : null;
            $this->repositorio->atualizar($emprestimo, ['transacao_recebimento_id' => $transacao]);
            $this->inserirParcelas($emprestimo, $dados, $agora);
            Entrada::auditar($usuario, 'emprestimo_criado', 'emprestimos', $emprestimo, [
                'parcelas' => $dados['quantidade_parcelas'], 'sistema' => $dados['sistema_amortizacao'],
                'afeta_saldo' => $transacao !== null,
            ]);

            return $emprestimo;
        });
    }

    public function atualizar(int $usuario, int $id, object $conta, array $dados): void
    {
        DB::transaction(function () use ($usuario, $id, $conta, $dados): void {
            $dados = $this->normalizarContrato($dados);
            $emprestimo = $this->emprestimoBloqueado($usuario, $id);
            $alteradas = $this->repositorio->parcelas($id)->contains(fn (object $parcela) => $parcela->situacao !== 'pendente');
            if ($alteradas) {
                abort_if(
                    $this->cronogramaFoiAlterado($emprestimo, $conta, $dados),
                    409,
                    'O cronograma não pode ser recalculado após o registro de pagamentos.',
                );
                $this->repositorio->atualizar($id, $this->dadosDescritivosPersistencia($dados));
                Entrada::auditar($usuario, 'emprestimo_atualizado', 'emprestimos', $id, [
                    'cronograma_preservado' => true,
                ]);

                return;
            }
            $this->repositorio->atualizar($id, $this->dadosPersistencia($usuario, $conta, $dados, now(), false));
            $this->sincronizarRecebimento($usuario, $emprestimo, $conta, $dados);
            $this->repositorio->removerParcelas($id);
            $this->inserirParcelas($id, $dados, now());
            Entrada::auditar($usuario, 'emprestimo_atualizado', 'emprestimos', $id, [
                'parcelas' => $dados['quantidade_parcelas'], 'sistema' => $dados['sistema_amortizacao'],
            ]);
        });
    }

    public function excluir(int $usuario, int $id): void
    {
        DB::transaction(function () use ($usuario, $id): void {
            $emprestimo = $this->emprestimoBloqueado($usuario, $id);
            $parcelas = $this->repositorio->parcelas($id, true);
            $transacoes = $parcelas->flatMap(fn (object $parcela) => [
                $parcela->transacao_principal_id, $parcela->transacao_encargos_id,
            ])->push($emprestimo->transacao_recebimento_id)->filter()->unique()->values();
            $this->repositorio->removerParcelas($id);
            $this->repositorio->excluir($id);
            $this->repositorio->excluirTransacoes($usuario, $transacoes);
            Entrada::auditar($usuario, 'emprestimo_excluido', 'emprestimos', $id, [
                'parcelas' => $parcelas->count(), 'transacoes_removidas' => $transacoes->count(),
            ]);
        });
    }

    public function pagarParcela(int $usuario, int $emprestimoId, int $parcelaId, ?object $conta, array $dados): void
    {
        DB::transaction(function () use ($usuario, $emprestimoId, $parcelaId, $conta, $dados): void {
            $emprestimo = $this->emprestimoBloqueado($usuario, $emprestimoId);
            $parcela = $this->repositorio->parcela($emprestimoId, $parcelaId, true);
            abort_if($parcela === null, 404);
            abort_unless($this->podeReceberPagamento($emprestimo, $parcela), 409);
            $afetarSaldo = $dados['afetar_saldo'] ?? true;
            abort_if($afetarSaldo && $conta === null, 422);
            if ($conta !== null) {
                $this->garantirMoeda($emprestimo, $conta);
            }
            [$principal, $encargos] = $afetarSaldo
                ? $this->registrarPagamento($usuario, $conta, $dados['data_pagamento'], $emprestimo, $parcela)
                : [null, null];
            $this->repositorio->atualizarParcela($parcelaId, [
                'situacao' => 'paga', 'data_pagamento' => $dados['data_pagamento'],
                'conta_pagamento_id' => $conta?->id, 'transacao_principal_id' => $principal,
                'transacao_encargos_id' => $encargos, 'valor_pago' => $parcela->valor_total,
                'juros_pago' => $parcela->juros,
                'amortizacao_paga' => bcadd((string) $parcela->principal, (string) $parcela->taxas, 2),
                'updated_at' => now(),
            ]);
            $this->concluirSeQuitado($emprestimo);
            Entrada::auditar($usuario, 'parcela_emprestimo_paga', 'parcelas_emprestimo', $parcelaId, [
                'afeta_saldo' => $afetarSaldo,
            ]);
        });
    }

    public function associarTransacao(int $usuario, int $emprestimoId, int $parcelaId, int $transacaoId): void
    {
        DB::transaction(function () use ($usuario, $emprestimoId, $parcelaId, $transacaoId): void {
            $emprestimo = $this->emprestimoBloqueado($usuario, $emprestimoId);
            $parcela = $this->repositorio->parcela($emprestimoId, $parcelaId, true);
            $transacao = $this->repositorio->transacao($usuario, $transacaoId, true);
            abort_if($parcela === null || $transacao === null, 404);
            abort_unless($this->podeReceberPagamento($emprestimo, $parcela), 409);
            abort_if($this->repositorio->transacaoJaAssociada($transacaoId), 409, 'A transação já está associada a outra parcela.');
            abort_unless($transacao->situacao === 'confirmada' && $transacao->moeda === $emprestimo->moeda, 422);
            $valorTransacao = ltrim((string) $transacao->valor, '-');
            abort_unless(bccomp((string) $transacao->valor, '0', 2) < 0 && bccomp($valorTransacao, (string) $parcela->valor_total, 2) === 0, 422, 'O valor da transação deve ser igual ao valor da parcela.');

            $this->repositorio->atualizarParcela($parcelaId, [
                'situacao' => 'paga', 'data_pagamento' => $transacao->data_efetiva,
                'conta_pagamento_id' => $transacao->conta_id, 'transacao_pagamento_id' => $transacaoId,
                'valor_pago' => $valorTransacao, 'juros_pago' => $parcela->juros,
                'amortizacao_paga' => bcadd((string) $parcela->principal, (string) $parcela->taxas, 2),
                'updated_at' => now(),
            ]);
            $this->concluirSeQuitado($emprestimo);
            Entrada::auditar($usuario, 'transacao_associada_parcela_emprestimo', 'parcelas_emprestimo', $parcelaId, ['transacao_id' => $transacaoId]);
        });
    }

    public function desfazerPagamentoParcela(int $usuario, int $emprestimoId, int $parcelaId): void
    {
        DB::transaction(function () use ($usuario, $emprestimoId, $parcelaId): void {
            $emprestimo = $this->emprestimoBloqueado($usuario, $emprestimoId);
            $parcela = $this->repositorio->parcela($emprestimoId, $parcelaId, true);
            abort_if($parcela === null, 404);
            abort_unless(
                in_array($emprestimo->situacao, ['ativo', 'quitado'], true) && $parcela->situacao === 'paga',
                409,
                'Somente uma parcela paga de um empréstimo ativo ou quitado pode ter o pagamento desfeito.',
            );

            $transacoes = collect([$parcela->transacao_principal_id, $parcela->transacao_encargos_id])
                ->filter()->unique()->values();
            $this->repositorio->atualizarParcela($parcelaId, $this->dadosParcelaPendente());
            $this->repositorio->excluirTransacoes($usuario, $transacoes);
            $this->repositorio->atualizar($emprestimoId, [
                'situacao' => 'ativo', 'quitado_em' => null, 'updated_at' => now(),
            ]);
            Entrada::auditar($usuario, 'pagamento_parcela_emprestimo_desfeito', 'parcelas_emprestimo', $parcelaId, [
                'transacoes_removidas' => $transacoes->count(),
                'transacao_associada_preservada' => $parcela->transacao_pagamento_id !== null,
            ]);
        });
    }

    public function quitar(int $usuario, int $emprestimoId, object $conta, array $dados): void
    {
        DB::transaction(function () use ($usuario, $emprestimoId, $conta, $dados): void {
            $emprestimo = $this->emprestimoBloqueado($usuario, $emprestimoId);
            abort_unless($emprestimo->situacao === 'ativo', 409);
            $this->garantirMoeda($emprestimo, $conta);
            $parcelas = $this->classificador->pendentes(
                $emprestimo,
                $this->repositorio->parcelas($emprestimoId, true),
            )->values();
            abort_if($parcelas->isEmpty(), 409);
            $principal = $parcelas->reduce(
                fn (string $total, object $item) => bcadd($total, (string) $item->principal, 2), '0.00'
            );
            $encargos = bcadd(Entrada::moeda($dados['juros']), Entrada::moeda($dados['taxas']), 2);
            $descricao = "Quitação antecipada: {$emprestimo->credor}";
            $transacaoPrincipal = $this->registrarTransacao($usuario, $conta, $dados['data_pagamento'], "-$principal", $descricao, 'pagamento_emprestimo', "emprestimo:$emprestimoId:quitacao:principal");
            $transacaoEncargos = bccomp($encargos, '0', 2) > 0
                ? $this->registrarEncargos($usuario, $conta, $dados['data_pagamento'], $encargos, $descricao, "emprestimo:$emprestimoId:quitacao:encargos") : null;
            foreach ($parcelas as $indice => $parcela) {
                $this->repositorio->atualizarParcela($parcela->id, [
                    'situacao' => 'quitada_antecipadamente', 'data_pagamento' => $dados['data_pagamento'],
                    'conta_pagamento_id' => $conta->id,
                    'transacao_principal_id' => $indice === 0 ? $transacaoPrincipal : null,
                    'transacao_encargos_id' => $indice === 0 ? $transacaoEncargos : null,
                    'valor_pago' => $indice === 0 ? bcadd($principal, $encargos, 2) : '0.00',
                    'juros_pago' => $indice === 0 ? $dados['juros'] : '0.00',
                    'amortizacao_paga' => bcadd((string) $parcela->principal, (string) $parcela->taxas, 2),
                    'updated_at' => now(),
                ]);
            }
            $this->repositorio->atualizar($emprestimoId, ['situacao' => 'quitado', 'quitado_em' => now(), 'updated_at' => now()]);
            Entrada::auditar($usuario, 'emprestimo_quitado', 'emprestimos', $emprestimoId, ['parcelas_antecipadas' => $parcelas->count()]);
        });
    }

    private function registrarPagamento(int $usuario, object $conta, string $data, object $emprestimo, object $parcela): array
    {
        $descricao = "Parcela {$parcela->numero}/{$emprestimo->quantidade_parcelas}: {$emprestimo->credor}";
        $principal = $this->registrarTransacao($usuario, $conta, $data, '-'.Entrada::moeda($parcela->principal), $descricao, 'pagamento_emprestimo', "emprestimo:$emprestimo->id:parcela:$parcela->id:principal");
        $custos = bcadd((string) $parcela->juros, (string) $parcela->taxas, 2);
        $encargos = bccomp($custos, '0', 2) > 0
            ? $this->registrarEncargos($usuario, $conta, $data, $custos, $descricao, "emprestimo:$emprestimo->id:parcela:$parcela->id:encargos") : null;

        return [$principal, $encargos];
    }

    private function dadosParcelaPendente(): array
    {
        return [
            'situacao' => 'pendente', 'data_pagamento' => null, 'conta_pagamento_id' => null,
            'transacao_principal_id' => null, 'transacao_encargos_id' => null,
            'transacao_pagamento_id' => null, 'valor_pago' => null, 'juros_pago' => null,
            'amortizacao_paga' => null, 'updated_at' => now(),
        ];
    }

    private function dadosPersistencia(int $usuario, object $conta, array $dados, mixed $agora, bool $criacao = true): array
    {
        $campos = [
            'conta_recebimento_id' => $conta->id, 'credor' => trim($dados['credor']),
            'descricao' => $this->opcional($dados['descricao'] ?? null), 'moeda' => $conta->moeda,
            'tipo_divida' => $dados['tipo_divida'],
            'valor_principal' => Entrada::moeda($dados['valor_recebido']),
            'valor_recebido' => Entrada::moeda($dados['valor_recebido']),
            'valor_financiado' => Entrada::moeda($dados['valor_financiado']),
            'iof' => Entrada::moeda($dados['iof']),
            'taxas_totais' => Entrada::moeda($dados['taxas_totais'] ?? '0'),
            'taxa_juros_mensal' => $dados['taxa_juros_mensal'],
            'taxa_juros_anual' => $dados['taxa_juros_anual'] ?? null,
            'cet_mensal' => $dados['cet_mensal'] ?? null,
            'cet_anual' => $dados['cet_anual'] ?? null,
            'sistema_amortizacao' => $dados['sistema_amortizacao'],
            'quantidade_parcelas' => $dados['quantidade_parcelas'],
            'valor_parcela_contratada' => $dados['valor_parcela_contratada'] ?? null,
            'total_contratado' => $dados['total_contratado'] ?? null,
            'data_contratacao' => $dados['data_contratacao'],
            'data_recebimento' => $dados['data_recebimento'],
            'data_base_calculo' => $dados['data_base_calculo'] ?? $dados['data_recebimento'],
            'primeiro_vencimento' => $dados['primeiro_vencimento'],
            'ultimo_vencimento' => CarbonImmutable::parse($dados['primeiro_vencimento'])
                ->addMonthsNoOverflow(((int) $dados['quantidade_parcelas']) - 1)->toDateString(),
            'updated_at' => $agora,
        ];

        return $criacao ? $campos + [
            'usuario_id' => $usuario, 'situacao' => 'ativo', 'created_at' => $agora,
        ] : $campos;
    }

    private function dadosDescritivosPersistencia(array $dados): array
    {
        return [
            'credor' => trim($dados['credor']),
            'descricao' => $this->opcional($dados['descricao'] ?? null),
            'tipo_divida' => $dados['tipo_divida'],
            'taxa_juros_anual' => $dados['taxa_juros_anual'] ?? null,
            'cet_mensal' => $dados['cet_mensal'] ?? null,
            'cet_anual' => $dados['cet_anual'] ?? null,
            'valor_parcela_contratada' => $dados['valor_parcela_contratada'] ?? null,
            'total_contratado' => $dados['total_contratado'] ?? null,
            'data_contratacao' => $dados['data_contratacao'],
            'updated_at' => now(),
        ];
    }

    private function cronogramaFoiAlterado(object $emprestimo, object $conta, array $dados): bool
    {
        if ((int) $emprestimo->conta_recebimento_id !== (int) $conta->id
            || (int) $emprestimo->quantidade_parcelas !== (int) $dados['quantidade_parcelas']
            || $emprestimo->sistema_amortizacao !== $dados['sistema_amortizacao']) {
            return true;
        }

        foreach ([
            'valor_recebido' => 2,
            'valor_financiado' => 2,
            'iof' => 2,
            'taxas_totais' => 2,
            'taxa_juros_mensal' => 6,
        ] as $campo => $escala) {
            if (! $this->decimaisIguais($emprestimo->{$campo}, $dados[$campo] ?? null, $escala)) {
                return true;
            }
        }

        foreach (['data_recebimento', 'data_base_calculo', 'primeiro_vencimento'] as $campo) {
            if (($emprestimo->{$campo} ?? null) !== ($dados[$campo] ?? null)) {
                return true;
            }
        }

        return ($emprestimo->transacao_recebimento_id !== null) !== ($dados['afetar_saldo_recebimento'] ?? true);
    }

    private function decimaisIguais(string|int|null $atual, string|int|null $novo, int $escala): bool
    {
        if ($atual === null || $novo === null || $atual === '' || $novo === '') {
            return ($atual === null || $atual === '') && ($novo === null || $novo === '');
        }

        return bccomp((string) $atual, (string) $novo, $escala) === 0;
    }

    private function inserirParcelas(int $emprestimo, array $dados, mixed $agora): void
    {
        $parcelas = array_map(fn (array $parcela) => $parcela + [
            'emprestimo_id' => $emprestimo, 'situacao' => 'pendente',
            'created_at' => $agora, 'updated_at' => $agora,
        ], $this->calculadora->calcular($dados));
        $this->repositorio->inserirParcelas($parcelas);
    }

    private function registrarRecebimento(int $usuario, object $conta, int $emprestimo, array $dados): int
    {
        return $this->registrarTransacao(
            $usuario,
            $conta,
            $dados['data_recebimento'],
            $dados['valor_principal'],
            "Empréstimo recebido de {$dados['credor']}",
            'emprestimo_recebido',
            "emprestimo:$emprestimo:recebimento",
        );
    }

    private function sincronizarRecebimento(int $usuario, object $emprestimo, object $conta, array $dados): void
    {
        $this->repositorio->atualizar($emprestimo->id, ['transacao_recebimento_id' => null]);
        if ($emprestimo->transacao_recebimento_id !== null) {
            $this->repositorio->excluirTransacoes($usuario, [$emprestimo->transacao_recebimento_id]);
        }
        if (! ($dados['afetar_saldo_recebimento'] ?? true)) {
            return;
        }
        $transacao = $this->registrarRecebimento($usuario, $conta, $emprestimo->id, $dados);
        $this->repositorio->atualizar($emprestimo->id, ['transacao_recebimento_id' => $transacao]);
    }

    private function registrarEncargos(int $usuario, object $conta, string $data, string $valor, string $descricao, string $identificador): int
    {
        $categoria = $this->categoriaEncargos($usuario);

        return $this->registrarTransacao($usuario, $conta, $data, '-'.$valor, "Juros e taxas — $descricao", 'saida', $identificador, $categoria);
    }

    private function registrarTransacao(int $usuario, object $conta, string $data, string $valor, string $descricao, string $natureza, string $identificador, ?int $categoria = null): int
    {
        $normalizada = Identidade::descricao($descricao);

        return $this->repositorio->criarTransacao([
            'usuario_id' => $usuario, 'conta_id' => $conta->id, 'categoria_id' => $categoria,
            'data_efetiva' => $data, 'valor' => Entrada::moeda($valor), 'moeda' => $conta->moeda,
            'descricao_original' => $descricao, 'descricao_normalizada' => $normalizada,
            'identificador_bancario' => $identificador,
            'fingerprint' => Identidade::fingerprint($usuario, $conta->id, $data, $valor, $conta->moeda, $identificador, $descricao),
            'natureza' => $natureza, 'situacao' => 'confirmada', 'origem' => 'emprestimo',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function categoriaEncargos(int $usuario): int
    {
        return $this->repositorio->categoriaEncargos($usuario);
    }

    private function emprestimoBloqueado(int $usuario, int $id): object
    {
        $emprestimo = $this->repositorio->buscar($usuario, $id, true);
        abort_if($emprestimo === null, 404);

        return $emprestimo;
    }

    private function garantirMoeda(object $emprestimo, object $conta): void
    {
        abort_unless($conta->situacao === 'ativa' && $conta->tipo !== 'cartao_credito' && $conta->moeda === $emprestimo->moeda, 422);
    }

    private function podeReceberPagamento(object $emprestimo, object $parcela): bool
    {
        return $emprestimo->situacao === 'ativo'
            && $parcela->situacao === 'pendente'
            && ! $this->classificador->naoSeAplica($emprestimo, $parcela);
    }

    private function concluirSeQuitado(object $emprestimo): void
    {
        $parcelas = $this->repositorio->parcelas($emprestimo->id);
        if ($this->classificador->pendentes($emprestimo, $parcelas)->isEmpty()) {
            $this->repositorio->atualizar($emprestimo->id, ['situacao' => 'quitado', 'quitado_em' => now(), 'updated_at' => now()]);
        }
    }

    private function normalizarContrato(array $dados): array
    {
        $recebido = Entrada::moeda($dados['valor_recebido'] ?? $dados['valor_principal']);
        $iof = Entrada::moeda($dados['iof'] ?? $dados['taxas_totais'] ?? '0');
        $financiado = Entrada::moeda($dados['valor_financiado'] ?? bcadd($recebido, $iof, 2));
        abort_if(bccomp($financiado, $recebido, 2) < 0, 422, 'O valor financiado não pode ser menor que o valor recebido.');
        $custosFinanciados = bcsub($financiado, $recebido, 2);
        abort_if(bccomp($iof, $custosFinanciados, 2) > 0, 422, 'O IOF não pode superar os custos financiados.');

        $dados['tipo_divida'] = $dados['tipo_divida'] ?? 'emprestimo_pessoal';
        $dados['valor_recebido'] = $recebido;
        $dados['valor_principal'] = $recebido;
        $dados['valor_financiado'] = $financiado;
        $dados['iof'] = $iof;
        $dados['taxas_totais'] = $custosFinanciados;
        $dados['data_contratacao'] = $dados['data_contratacao'] ?? $dados['data_recebimento'];
        $dados['data_base_calculo'] = $dados['data_base_calculo'] ?? $dados['data_recebimento'];
        if ($dados['sistema_amortizacao'] === 'price'
            && ! isset($dados['total_contratado'])
            && isset($dados['valor_parcela_contratada'])) {
            $dados['total_contratado'] = bcmul((string) $dados['valor_parcela_contratada'], (string) $dados['quantidade_parcelas'], 2);
        }

        return $dados;
    }

    private function opcional(?string $valor): ?string
    {
        $limpo = trim((string) $valor);

        return $limpo === '' ? null : $limpo;
    }
}
