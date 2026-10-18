<?php

use App\Modulos\Administracao\Controladores\ControladorAdministracao;
use App\Modulos\Assinaturas\Controladores\ControladorAdministracaoAssinaturas;
use App\Modulos\Assinaturas\Controladores\ControladorAssinaturas;
use App\Modulos\Assinaturas\Controladores\ControladorWebhookMercadoPago;
use App\Modulos\Assinaturas\Dominio\RecursosPlano;
use App\Modulos\Financeiro\Controladores\ControladorCategorias;
use App\Modulos\Financeiro\Controladores\ControladorContas;
use App\Modulos\Financeiro\Controladores\ControladorEmprestimos;
use App\Modulos\Financeiro\Controladores\ControladorFaturasCartao;
use App\Modulos\Financeiro\Controladores\ControladorImportacoes;
use App\Modulos\Financeiro\Controladores\ControladorMetasFinanceiras;
use App\Modulos\Financeiro\Controladores\ControladorPagamentosRecorrentes;
use App\Modulos\Financeiro\Controladores\ControladorRelatorios;
use App\Modulos\Financeiro\Controladores\ControladorResumo;
use App\Modulos\Financeiro\Controladores\ControladorTransacoes;
use App\Modulos\Usuarios\Controladores\ControladorPrivacidade;
use App\Modulos\Usuarios\Controladores\ControladorRecuperacao;
use App\Modulos\Usuarios\Controladores\ControladorUsuario;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/usuarios', [ControladorUsuario::class, 'cadastrar'])->middleware('throttle:cadastro');
    Route::post('/entrar', [ControladorUsuario::class, 'entrar'])->middleware('throttle:entrada');
    Route::post('/renovar', [ControladorUsuario::class, 'renovar'])->middleware('throttle:entrada');
    Route::post('/recuperar-acesso', [ControladorRecuperacao::class, 'solicitar'])->middleware('throttle:recuperacao');
    Route::post('/redefinir-senha', [ControladorRecuperacao::class, 'redefinir'])->middleware('throttle:recuperacao');
    Route::post('/webhooks/mercado-pago/assinaturas', [ControladorWebhookMercadoPago::class, 'receber'])
        ->middleware('throttle:webhook-assinatura');

    Route::middleware(['autenticacao', 'throttle:autenticado'])->group(function (): void {
        Route::get('/usuarios/eu', [ControladorUsuario::class, 'atual']);
        Route::get('/usuarios/eu/exportacao', [ControladorPrivacidade::class, 'exportar']);
        Route::delete('/usuarios/eu', [ControladorPrivacidade::class, 'excluir']);
        Route::post('/sair', [ControladorUsuario::class, 'sair']);
        Route::get('/usuarios', [ControladorUsuario::class, 'listar']);
        Route::get('/planos', [ControladorAssinaturas::class, 'planos']);
        Route::get('/assinatura', [ControladorAssinaturas::class, 'atual']);
        Route::post('/assinatura/checkout', [ControladorAssinaturas::class, 'checkout'])->middleware('throttle:checkout-assinatura');

        Route::prefix('admin/assinaturas')->middleware('administrador')->group(function (): void {
            Route::get('/resumo', [ControladorAdministracaoAssinaturas::class, 'resumo']);
            Route::get('/planos', [ControladorAdministracaoAssinaturas::class, 'planos']);
            Route::post('/planos', [ControladorAdministracaoAssinaturas::class, 'criarPlano']);
            Route::patch('/planos/{uuid}', [ControladorAdministracaoAssinaturas::class, 'atualizarPlano']);
            Route::get('/', [ControladorAdministracaoAssinaturas::class, 'assinaturas']);
            Route::post('/cortesia', [ControladorAdministracaoAssinaturas::class, 'cortesia']);
            Route::post('/{uuid}/reconciliacao', [ControladorAdministracaoAssinaturas::class, 'reconciliar']);
            Route::get('/eventos', [ControladorAdministracaoAssinaturas::class, 'eventos']);
        });

        Route::prefix('admin')->middleware('administrador')->group(function (): void {
            Route::get('/dashboard', [ControladorAdministracao::class, 'dashboard']);
            Route::get('/usuarios/exportacao.csv', [ControladorAdministracao::class, 'exportarUsuarios']);
            Route::get('/usuarios', [ControladorAdministracao::class, 'usuarios']);
            Route::get('/relatorios/{tipo}/exportacao.csv', [ControladorAdministracao::class, 'exportar']);
            Route::get('/relatorios/{tipo}', [ControladorAdministracao::class, 'relatorio']);
            Route::get('/operacoes', [ControladorAdministracao::class, 'operacoes']);
        });

        Route::middleware(['identificadores.publicos', 'assinatura:'.RecursosPlano::CONTAS_MOVIMENTACOES])->group(function (): void {
            Route::get('/contas', [ControladorContas::class, 'listar']);
            Route::post('/contas', [ControladorContas::class, 'criar']);
            Route::patch('/contas/{id}', [ControladorContas::class, 'atualizar']);
            Route::post('/contas/{id}/ajustes', [ControladorContas::class, 'ajustar']);
            Route::get('/contas/{id}/faturas', [ControladorFaturasCartao::class, 'listar']);
            Route::get('/contas/{id}/faturas/{faturaId}', [ControladorFaturasCartao::class, 'mostrar']);
            Route::post('/contas/{id}/faturas/{faturaId}/fechamento', [ControladorFaturasCartao::class, 'fecharAgora']);
            Route::post('/contas/{id}/faturas/{faturaId}/adiantamentos', [ControladorFaturasCartao::class, 'adiantar']);
            Route::post('/contas/{id}/faturas/{faturaId}/pagamento', [ControladorFaturasCartao::class, 'pagar']);
            Route::patch('/contas/{id}/faturas/{faturaId}/lancamentos/{lancamentoId}', [ControladorFaturasCartao::class, 'corrigirLancamento']);
            Route::delete('/contas/{id}/faturas/{faturaId}/lancamentos/{lancamentoId}', [ControladorFaturasCartao::class, 'cancelarLancamento']);
            Route::get('/categorias', [ControladorCategorias::class, 'listar']);
            Route::post('/categorias', [ControladorCategorias::class, 'criar']);
            Route::patch('/categorias/{id}', [ControladorCategorias::class, 'atualizar']);
            Route::get('/transacoes', [ControladorTransacoes::class, 'listar']);
            Route::post('/transacoes', [ControladorTransacoes::class, 'criar']);
            Route::patch('/transacoes/{id}', [ControladorTransacoes::class, 'editar']);
            Route::delete('/transacoes/{id}', [ControladorTransacoes::class, 'cancelar']);
            Route::patch('/transacoes/{id}/classificacao', [ControladorTransacoes::class, 'classificar']);
            Route::post('/transacoes/{id}/estornos', [ControladorTransacoes::class, 'estornar']);
            Route::post('/transacoes/{id}/contrapartida', [ControladorTransacoes::class, 'vincularContrapartida']);
            Route::post('/transferencias', [ControladorTransacoes::class, 'transferir']);
        });
        Route::middleware(['identificadores.publicos', 'assinatura:'.RecursosPlano::PAGAMENTOS_RECORRENTES])->group(function (): void {
            Route::get('/pagamentos-recorrentes', [ControladorPagamentosRecorrentes::class, 'listar']);
            Route::post('/pagamentos-recorrentes', [ControladorPagamentosRecorrentes::class, 'criar']);
            Route::patch('/pagamentos-recorrentes/{id}', [ControladorPagamentosRecorrentes::class, 'atualizar']);
            Route::post('/pagamentos-recorrentes/{id}/pagamentos', [ControladorPagamentosRecorrentes::class, 'pagar']);
        });
        Route::middleware(['identificadores.publicos', 'assinatura:'.RecursosPlano::METAS_FINANCEIRAS])->group(function (): void {
            Route::get('/metas-financeiras', [ControladorMetasFinanceiras::class, 'listar']);
            Route::post('/metas-financeiras', [ControladorMetasFinanceiras::class, 'criar']);
            Route::patch('/metas-financeiras/{id}', [ControladorMetasFinanceiras::class, 'atualizar']);
            Route::post('/metas-financeiras/{id}/aportes', [ControladorMetasFinanceiras::class, 'aportar']);
        });
        Route::middleware(['identificadores.publicos', 'assinatura:'.RecursosPlano::VISAO_GERAL])->group(function (): void {
            Route::get('/resumo', [ControladorResumo::class, 'mostrar']);
            Route::get('/relatorios/evolucao', [ControladorRelatorios::class, 'evolucao']);
            Route::get('/relatorios/comparacao-mensal', [ControladorRelatorios::class, 'comparacaoMensal']);
        });
        Route::middleware(['identificadores.publicos', 'assinatura:'.RecursosPlano::EMPRESTIMOS])->group(function (): void {
            Route::get('/emprestimos', [ControladorEmprestimos::class, 'listar']);
            Route::post('/emprestimos', [ControladorEmprestimos::class, 'criar']);
            Route::get('/emprestimos/{id}', [ControladorEmprestimos::class, 'mostrar']);
            Route::get('/emprestimos/{id}/cronograma', [ControladorEmprestimos::class, 'cronograma']);
            Route::patch('/emprestimos/{id}', [ControladorEmprestimos::class, 'atualizar']);
            Route::delete('/emprestimos/{id}', [ControladorEmprestimos::class, 'excluir']);
            Route::post('/emprestimos/{id}/parcelas/{parcelaId}/pagamentos', [ControladorEmprestimos::class, 'pagarParcela']);
            Route::delete('/emprestimos/{id}/parcelas/{parcelaId}/pagamentos', [ControladorEmprestimos::class, 'desfazerPagamentoParcela']);
            Route::post('/emprestimos/{id}/parcelas/{parcelaId}/transacao', [ControladorEmprestimos::class, 'associarTransacao']);
            Route::post('/emprestimos/{id}/simulacao-quitacao', [ControladorEmprestimos::class, 'simularQuitacao']);
            Route::post('/emprestimos/{id}/quitacao', [ControladorEmprestimos::class, 'quitar']);
        });
        Route::middleware(['identificadores.publicos', 'assinatura:'.RecursosPlano::IMPORTACAO_EXTRATOS])->group(function (): void {
            Route::get('/importacoes', [ControladorImportacoes::class, 'listar']);
            Route::post('/importacoes', [ControladorImportacoes::class, 'criar'])->middleware('throttle:importacao');
            Route::get('/importacoes/{id}/previa', [ControladorImportacoes::class, 'previa']);
            Route::patch('/importacoes/{id}/linhas/{linhaId}', [ControladorImportacoes::class, 'corrigirLinha']);
            Route::post('/importacoes/{id}/confirmacao', [ControladorImportacoes::class, 'confirmar']);
            Route::post('/importacoes/{id}/reversao', [ControladorImportacoes::class, 'reverter']);
            Route::post('/importacoes/{id}/cancelamento', [ControladorImportacoes::class, 'cancelar']);
        });
    });
});
