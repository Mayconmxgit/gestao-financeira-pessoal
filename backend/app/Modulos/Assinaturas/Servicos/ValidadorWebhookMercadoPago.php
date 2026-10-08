<?php

namespace App\Modulos\Assinaturas\Servicos;

use Illuminate\Http\Request;

final class ValidadorWebhookMercadoPago
{
    public function validar(Request $requisicao): ?array
    {
        $recurso = $requisicao->query('data.id', $requisicao->input('data.id'));
        $requisicaoId = $requisicao->header('x-request-id');
        $partes = $this->partesAssinatura((string) $requisicao->header('x-signature'));
        if (! is_string($recurso) || ! is_string($requisicaoId) || ! isset($partes['ts'], $partes['v1'])) {
            return null;
        }
        if (! ctype_digit($partes['ts']) || ! $this->timestampValido((int) $partes['ts'])) {
            return null;
        }
        $segredo = (string) config('assinaturas.mercado_pago.segredo_webhook');
        if ($segredo === '') {
            return null;
        }
        $mensagem = 'id:'.mb_strtolower($recurso).';request-id:'.$requisicaoId.';ts:'.$partes['ts'].';';
        $esperada = hash_hmac('sha256', $mensagem, $segredo);

        return hash_equals($esperada, $partes['v1'])
            ? ['recurso' => $recurso, 'requisicao' => $requisicaoId]
            : null;
    }

    private function partesAssinatura(string $assinatura): array
    {
        $partes = [];
        foreach (explode(',', $assinatura) as $item) {
            [$chave, $valor] = array_pad(explode('=', trim($item), 2), 2, null);
            if (is_string($chave) && is_string($valor)) {
                $partes[$chave] = $valor;
            }
        }

        return $partes;
    }

    private function timestampValido(int $milissegundos): bool
    {
        $diferenca = abs(time() - intdiv($milissegundos, 1000));

        return $diferenca <= (int) config('assinaturas.mercado_pago.tolerancia_webhook_segundos');
    }
}
