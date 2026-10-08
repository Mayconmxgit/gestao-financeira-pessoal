<?php

namespace App\Modulos\Assinaturas\Controladores;

use App\Http\Controllers\Controller;
use App\Modulos\Assinaturas\Servicos\ServicoAssinaturas;
use App\Modulos\Assinaturas\Servicos\ValidadorWebhookMercadoPago;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ControladorWebhookMercadoPago extends Controller
{
    public function receber(Request $requisicao, ValidadorWebhookMercadoPago $validador, ServicoAssinaturas $servico): JsonResponse
    {
        $origem = $validador->validar($requisicao);
        abort_if($origem === null, 403);
        $evento = $this->registrar($requisicao, $origem);
        if (! $evento['novo']) {
            return response()->json(['recebido' => true]);
        }
        try {
            $servico->sincronizarPorIdentificador($origem['recurso']);
            DB::table('eventos_webhook_assinatura')->where('id', $evento['id'])->update([
                'situacao' => 'processado', 'processado_em' => now(), 'updated_at' => now(),
            ]);
        } catch (Throwable $excecao) {
            DB::table('eventos_webhook_assinatura')->where('id', $evento['id'])->update([
                'situacao' => 'falha', 'codigo_erro' => class_basename($excecao), 'updated_at' => now(),
            ]);
            abort(503);
        }

        return response()->json(['recebido' => true]);
    }

    private function registrar(Request $requisicao, array $origem): array
    {
        $existente = DB::table('eventos_webhook_assinatura')->where('provedor', 'mercado_pago')
            ->where('requisicao_id', $origem['requisicao'])->first();
        if ($existente) {
            return ['id' => $existente->id, 'novo' => false];
        }
        $id = DB::table('eventos_webhook_assinatura')->insertGetId([
            'uuid' => (string) Str::uuid(), 'provedor' => 'mercado_pago',
            'requisicao_id' => $origem['requisicao'], 'recurso_id' => $origem['recurso'],
            'tipo' => mb_substr((string) $requisicao->input('type', 'subscription_preapproval'), 0, 80),
            'acao' => mb_substr((string) $requisicao->input('action', ''), 0, 100) ?: null,
            'situacao' => 'recebido', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'novo' => true];
    }
}
