<?php

namespace App\Modulos\Financeiro\Middlewares;

use App\Modulos\Financeiro\Suporte\IdentificadorPublico;
use App\Modulos\Financeiro\Suporte\PublicadorIdentificadores;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class MiddlewareIdentificadoresPublicos
{
    private const PARAMETROS_ROTA = [
        'contas' => ['id' => 'contas'], 'categorias' => ['id' => 'categorias'],
        'transacoes' => ['id' => 'transacoes'], 'pagamentos-recorrentes' => ['id' => 'pagamentos_recorrentes'],
        'metas-financeiras' => ['id' => 'metas_financeiras'],
        'emprestimos' => ['id' => 'emprestimos', 'parcelaId' => 'parcelas_emprestimo'],
        'importacoes' => ['id' => 'lotes_importacao', 'linhaId' => 'linhas_importacao'],
    ];

    private const CAMPOS = [
        'conta_id' => 'contas', 'conta_origem_id' => 'contas', 'conta_destino_id' => 'contas',
        'origem_id' => 'contas', 'destino_id' => 'contas',
        'conta_contrapartida_id' => 'contas', 'conta_recebimento_id' => 'contas', 'conta_pagamento_id' => 'contas',
        'categoria_id' => 'categorias', 'linha_id' => 'linhas_importacao',
        'transacao_id' => 'transacoes', 'transacao_candidata_id' => 'transacoes',
    ];

    public function __construct(
        private readonly IdentificadorPublico $identificadores,
        private readonly PublicadorIdentificadores $publicador,
    ) {}

    public function handle(Request $requisicao, Closure $proximo): Response
    {
        $usuario = (int) $requisicao->attributes->get('usuario_atual')->id;
        $recurso = $this->recurso($requisicao);
        $mapasDaRota = $this->converterParametrosRota($requisicao, $recurso, $usuario);
        $requisicao->merge($this->converterCampos($requisicao->all(), $usuario));
        if ($recurso === 'transacoes' && $requisicao->query->has('apos')) {
            $cursor = $this->resolver('transacoes', (string) $requisicao->query('apos'), $usuario, 'apos');
            $requisicao->query->set('apos', $cursor);
            $requisicao->merge(['apos' => $cursor]);
        }

        $resposta = $proximo($requisicao);
        if ($resposta instanceof JsonResponse) {
            $contexto = $this->contexto($recurso);
            $resposta->setData($this->publicador->publicar($resposta->getData(true), $contexto, $mapasDaRota));
        }

        return $resposta;
    }

    private function converterParametrosRota(Request $requisicao, string $recurso, int $usuario): array
    {
        $mapas = [];
        foreach (self::PARAMETROS_ROTA[$recurso] ?? [] as $parametro => $tabela) {
            $valor = $requisicao->route($parametro);
            if ($valor !== null) {
                $id = $this->resolver($tabela, (string) $valor, $usuario, $parametro, true);
                $mapas[$tabela][$id] = (string) $valor;
                $requisicao->route()->setParameter($parametro, $id);
            }
        }

        return $mapas;
    }

    private function converterCampos(array $dados, int $usuario): array
    {
        foreach ($dados as $chave => $valor) {
            if (is_array($valor)) {
                $dados[$chave] = $this->converterCampos($valor, $usuario);

                continue;
            }
            if ($valor !== null && isset(self::CAMPOS[$chave])) {
                $dados[$chave] = $this->resolver(self::CAMPOS[$chave], (string) $valor, $usuario, $chave);
            }
        }

        return $dados;
    }

    private function resolver(string $tabela, string $uuid, int $usuario, string $campo, bool $rota = false): int
    {
        $id = $this->identificadores->resolver($tabela, $uuid, $usuario);
        if ($id !== null) {
            return $id;
        }
        if ($rota) {
            abort(404);
        }
        throw ValidationException::withMessages([$campo => 'O identificador informado é inválido.']);
    }

    private function recurso(Request $requisicao): string
    {
        $segmentos = $requisicao->segments();

        return (string) ($segmentos[2] ?? '');
    }

    private function contexto(string $recurso): ?string
    {
        return [
            'contas' => 'contas', 'categorias' => 'categorias', 'transacoes' => 'transacoes',
            'pagamentos-recorrentes' => 'pagamentos_recorrentes', 'metas-financeiras' => 'metas_financeiras',
            'emprestimos' => 'emprestimos', 'importacoes' => 'lotes_importacao',
        ][$recurso] ?? null;
    }
}
