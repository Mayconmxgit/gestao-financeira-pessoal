<?php

namespace App\Modulos\Assinaturas\Servicos;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ClienteMercadoPagoAssinaturas
{
    public function disponivel(): bool
    {
        return (bool) config('assinaturas.mercado_pago.ativo')
            && is_string(config('assinaturas.mercado_pago.token'))
            && trim((string) config('assinaturas.mercado_pago.token')) !== '';
    }

    public function criar(array $assinatura, object $plano, object $usuario): array
    {
        $resposta = $this->http((string) $assinatura['referencia_externa'])->post('/preapproval', [
            'reason' => $plano->nome,
            'external_reference' => $assinatura['referencia_externa'],
            'payer_email' => $usuario->email,
            'auto_recurring' => [
                'frequency' => (int) $plano->intervalo,
                'frequency_type' => $plano->periodicidade === 'anual' ? 'years' : 'months',
                'transaction_amount' => (float) $plano->valor,
                'currency_id' => $plano->moeda,
            ],
            'back_url' => config('assinaturas.mercado_pago.url_retorno'),
            'status' => 'pending',
        ]);
        if (! $resposta->successful()) {
            throw new RuntimeException('Falha ao criar assinatura no provedor.');
        }

        $dados = $this->validarResposta($resposta->json(), ['id', 'init_point', 'status']);
        if (! $this->urlCheckoutConfiavel($dados['init_point'])) {
            throw new RuntimeException('URL de checkout inválida recebida do provedor.');
        }

        return $dados;
    }

    public function consultar(string $identificador): array
    {
        $resposta = $this->http()->get('/preapproval/'.rawurlencode($identificador));
        if (! $resposta->successful()) {
            throw new RuntimeException('Falha ao consultar assinatura no provedor.');
        }

        return $this->validarResposta($resposta->json(), ['id', 'status']);
    }

    private function http(?string $idempotencia = null): PendingRequest
    {
        if (! $this->disponivel()) {
            throw new RuntimeException('Integração de assinaturas indisponível.');
        }
        $requisicao = Http::baseUrl((string) config('assinaturas.mercado_pago.url'))
            ->acceptJson()->asJson()->withToken((string) config('assinaturas.mercado_pago.token'))->timeout(15);

        return $idempotencia ? $requisicao->withHeaders(['X-Idempotency-Key' => $idempotencia]) : $requisicao;
    }

    private function validarResposta(mixed $dados, array $campos): array
    {
        if (! is_array($dados)) {
            throw new RuntimeException('Resposta inválida do provedor.');
        }
        foreach ($campos as $campo) {
            if (! isset($dados[$campo]) || ! is_string($dados[$campo]) || trim($dados[$campo]) === '') {
                throw new RuntimeException('Resposta incompleta do provedor.');
            }
        }

        return $dados;
    }

    private function urlCheckoutConfiavel(string $url): bool
    {
        $partes = parse_url($url);
        if (($partes['scheme'] ?? null) !== 'https' || ! isset($partes['host'])) {
            return false;
        }
        $host = strtolower($partes['host']);

        return $host === 'mercadopago.com' || str_ends_with($host, '.mercadopago.com')
            || $host === 'mercadopago.com.br' || str_ends_with($host, '.mercadopago.com.br');
    }
}
