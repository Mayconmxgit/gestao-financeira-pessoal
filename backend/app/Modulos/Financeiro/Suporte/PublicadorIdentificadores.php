<?php

namespace App\Modulos\Financeiro\Suporte;

final class PublicadorIdentificadores
{
    private const CHAVES = [
        'conta_id' => 'contas', 'conta_recebimento_id' => 'contas', 'conta_pagamento_id' => 'contas',
        'conta_origem_id' => 'contas', 'conta_destino_id' => 'contas', 'conta_contrapartida_id' => 'contas',
        'categoria_id' => 'categorias', 'lote_id' => 'lotes_importacao', 'linha_id' => 'linhas_importacao',
        'linha_candidata_id' => 'linhas_importacao', 'transacao_id' => 'transacoes',
        'transacao_relacionada_id' => 'transacoes', 'transacao_candidata_id' => 'transacoes',
        'transacao_criada_id' => 'transacoes', 'transacao_recebimento_id' => 'transacoes',
        'transacao_principal_id' => 'transacoes', 'transacao_encargos_id' => 'transacoes',
        'transacao_pagamento_id' => 'transacoes', 'pagamento_recorrente_id' => 'pagamentos_recorrentes',
        'meta_id' => 'metas_financeiras', 'emprestimo_id' => 'emprestimos', 'parcela_id' => 'parcelas_emprestimo',
        'cartao_id' => 'contas', 'fatura_cartao_id' => 'faturas_cartao', 'fatura_id' => 'faturas_cartao',
    ];

    private const CONTEXTOS = [
        'contas' => 'contas', 'categorias' => 'categorias', 'despesas_por_categoria' => 'categorias',
        'lote' => 'lotes_importacao', 'linhas' => 'linhas_importacao', 'transacao' => 'transacoes',
        'origem' => 'transacoes', 'destino' => 'transacoes', 'original' => 'transacoes',
        'contrapartida' => 'transacoes', 'pagamento' => 'pagamentos_recorrentes',
        'parcelas_compra' => 'transacoes',
        'meta' => 'metas_financeiras', 'aportes' => 'aportes_metas', 'emprestimo' => 'emprestimos',
        'parcelas' => 'parcelas_emprestimo', 'proxima_parcela' => 'parcelas_emprestimo',
        'faturas' => 'faturas_cartao', 'transacoes' => 'transacoes',
        'fatura' => 'faturas_cartao', 'fatura_atual' => 'faturas_cartao',
    ];

    public function __construct(private readonly IdentificadorPublico $identificadores) {}

    public function publicar(mixed $dados, ?string $contexto, array $mapasAdicionais = []): mixed
    {
        $ids = [];
        $this->coletar($dados, $contexto, $ids);
        $mapas = array_replace_recursive($this->identificadores->mapear($ids), $mapasAdicionais);

        return $this->substituir($dados, $contexto, $mapas);
    }

    private function coletar(mixed $dados, ?string $contexto, array &$ids): void
    {
        if (is_object($dados)) {
            $dados = (array) $dados;
        }
        if (! is_array($dados)) {
            return;
        }
        foreach ($dados as $chave => $valor) {
            $tabela = $this->tabelaDaChave($chave, $contexto);
            if ($tabela !== null && is_numeric($valor)) {
                $ids[$tabela][(int) $valor] = true;
            }
            $this->coletar($valor, $this->contextoFilho($chave, $contexto), $ids);
        }
    }

    private function substituir(mixed $dados, ?string $contexto, array $mapas): mixed
    {
        if (is_object($dados)) {
            $dados = (array) $dados;
        }
        if (! is_array($dados)) {
            return $dados;
        }
        foreach ($dados as $chave => $valor) {
            if ($chave === 'uuid') {
                unset($dados[$chave]);

                continue;
            }
            $tabela = $this->tabelaDaChave($chave, $contexto);
            if ($tabela !== null && is_numeric($valor)) {
                $dados[$chave] = $mapas[$tabela][(int) $valor]
                    ?? throw new \RuntimeException("Identificador público ausente em {$tabela}.");

                continue;
            }
            $dados[$chave] = $this->substituir($valor, $this->contextoFilho($chave, $contexto), $mapas);
        }

        return $dados;
    }

    private function tabelaDaChave(int|string $chave, ?string $contexto): ?string
    {
        if ($chave === 'id') {
            return $contexto;
        }
        if ($chave === 'proximo_cursor' && $contexto === 'transacoes') {
            return 'transacoes';
        }

        return is_string($chave) ? (self::CHAVES[$chave] ?? null) : null;
    }

    private function contextoFilho(int|string $chave, ?string $contexto): ?string
    {
        return is_string($chave) ? (self::CONTEXTOS[$chave] ?? $contexto) : $contexto;
    }
}
