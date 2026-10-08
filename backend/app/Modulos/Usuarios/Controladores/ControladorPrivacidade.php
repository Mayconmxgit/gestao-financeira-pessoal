<?php

namespace App\Modulos\Usuarios\Controladores;

use App\Http\Controllers\Controller;
use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ControladorPrivacidade extends Controller
{
    public function exportar(Request $requisicao): StreamedResponse
    {
        $usuario = Entrada::usuario($requisicao);

        return response()->streamDownload(function () use ($usuario): void {
            $perfil = DB::table('usuarios')->where('id', $usuario)
                ->first(['id', 'nome', 'email', 'papel', 'criado_em', 'atualizado_em']);
            echo substr(json_encode(['gerado_em' => now()->toIso8601String(), 'usuario' => $perfil], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 0, -1);
            foreach (['contas', 'categorias', 'lotes_importacao', 'transacoes', 'pagamentos_recorrentes', 'execucoes_pagamentos_recorrentes', 'metas_financeiras', 'aportes_metas', 'emprestimos', 'decisoes_conciliacao', 'auditoria_financeira', 'consentimentos_ia', 'uso_ia'] as $tabela) {
                $this->emitirTabela($tabela, DB::table($tabela)->where('usuario_id', $usuario)->orderBy('id')->cursor());
            }
            $assinaturas = DB::table('assinaturas')->where('usuario_id', $usuario)->orderBy('id')->cursor();
            $this->emitirTabela('assinaturas', $assinaturas);
            $cobrancas = DB::table('cobrancas_assinatura as cobranca')->join('assinaturas as assinatura', 'assinatura.id', '=', 'cobranca.assinatura_id')
                ->where('assinatura.usuario_id', $usuario)->select('cobranca.*')->orderBy('cobranca.id')->cursor();
            $this->emitirTabela('cobrancas_assinatura', $cobrancas);
            $parcelas = DB::table('parcelas_emprestimo as parcela')->join('emprestimos as emprestimo', 'emprestimo.id', '=', 'parcela.emprestimo_id')
                ->where('emprestimo.usuario_id', $usuario)->select('parcela.*')->orderBy('parcela.id')->cursor();
            $this->emitirTabela('parcelas_emprestimo', $parcelas);
            $linhas = DB::table('linhas_importacao as linha')->join('lotes_importacao as lote', 'lote.id', '=', 'linha.lote_id')
                ->where('lote.usuario_id', $usuario)->select('linha.*')->orderBy('linha.id')->cursor();
            $this->emitirTabela('linhas_importacao', $linhas);
            $conversas = DB::table('perguntas_ia')->where('usuario_id', $usuario)->orderBy('id')->cursor();
            $this->emitirTabela('perguntas_ia', $conversas, true);
            echo '}';
        }, 'vida-financeira-exportacao.json', [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function emitirTabela(string $nome, iterable $linhas, bool $descriptografar = false): void
    {
        echo ','.json_encode($nome, JSON_THROW_ON_ERROR).':';
        echo '[';
        $separador = '';
        foreach ($linhas as $linha) {
            $dados = (array) $linha;
            if ($descriptografar) {
                $dados['pergunta'] = Crypt::decryptString($dados['pergunta_cifrada']);
                $dados['resposta'] = $dados['resposta_cifrada'] ? Crypt::decryptString($dados['resposta_cifrada']) : null;
                if (! empty($dados['dados_usados_cifrados'])) {
                    $dados['dados_usados'] = json_decode(
                        Crypt::decryptString($dados['dados_usados_cifrados']),
                        true,
                        512,
                        JSON_THROW_ON_ERROR,
                    );
                }
                unset($dados['pergunta_cifrada'], $dados['resposta_cifrada'], $dados['dados_usados_cifrados']);
            }
            echo $separador.json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $separador = ',';
        }
        echo ']';
    }

    public function excluir(Request $requisicao): JsonResponse
    {
        $dados = Entrada::validar($requisicao, [
            'senha' => ['required', 'string', 'max:128'],
            'confirmar' => ['required', 'accepted'],
        ]);
        $usuario = $requisicao->attributes->get('usuario_atual');
        $hash = DB::table('usuarios')->where('id', $usuario->id)->value('senha');
        if (! is_string($hash) || ! Hash::driver('argon2id')->check($dados['senha'], $hash)) {
            throw ValidationException::withMessages(['senha' => 'Credenciais inválidas.']);
        }
        DB::transaction(function () use ($usuario): void {
            $id = $usuario->id;
            $assinaturas = DB::table('assinaturas')->where('usuario_id', $id)->pluck('id');
            DB::table('cobrancas_assinatura')->whereIn('assinatura_id', $assinaturas)->delete();
            DB::table('assinaturas')->where('usuario_id', $id)->delete();
            DB::table('auditoria_assinaturas')->where('administrador_id', $id)->delete();
            DB::table('uso_ia')->where('usuario_id', $id)->delete();
            DB::table('perguntas_ia')->where('usuario_id', $id)->delete();
            DB::table('consentimentos_ia')->where('usuario_id', $id)->delete();
            DB::table('decisoes_conciliacao')->where('usuario_id', $id)->delete();
            $emprestimos = DB::table('emprestimos')->where('usuario_id', $id)->pluck('id');
            DB::table('parcelas_emprestimo')->whereIn('emprestimo_id', $emprestimos)->delete();
            DB::table('emprestimos')->where('usuario_id', $id)->delete();
            DB::table('execucoes_pagamentos_recorrentes')->where('usuario_id', $id)->delete();
            DB::table('pagamentos_recorrentes')->where('usuario_id', $id)->delete();
            DB::table('aportes_metas')->where('usuario_id', $id)->delete();
            DB::table('metas_financeiras')->where('usuario_id', $id)->delete();
            DB::table('transacoes')->where('usuario_id', $id)->update(['transacao_relacionada_id' => null]);
            DB::table('transacoes')->where('usuario_id', $id)->delete();
            $lotes = DB::table('lotes_importacao')->where('usuario_id', $id)->pluck('id');
            DB::table('linhas_importacao')->whereIn('lote_id', $lotes)->delete();
            DB::table('lotes_importacao')->where('usuario_id', $id)->delete();
            DB::table('categorias')->where('usuario_id', $id)->delete();
            DB::table('contas')->where('usuario_id', $id)->delete();
            DB::table('auditoria_financeira')->where('usuario_id', $id)->delete();
            DB::table('recuperacoes_acesso')->where('email', $usuario->email)->delete();
            DB::table('usuarios')->where('id', $id)->delete();
        });

        return response()->json(['mensagem' => 'Conta e dados associados excluídos.']);
    }
}
