<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emprestimos', function (Blueprint $tabela): void {
            $tabela->string('tipo_divida', 30)->default('emprestimo_pessoal')->after('descricao');
            $tabela->decimal('valor_recebido', 18, 2)->nullable()->after('moeda');
            $tabela->decimal('valor_financiado', 18, 2)->nullable()->after('valor_recebido');
            $tabela->decimal('iof', 18, 2)->default(0)->after('valor_financiado');
            $tabela->decimal('taxa_juros_anual', 9, 6)->nullable()->after('taxa_juros_mensal');
            $tabela->decimal('cet_mensal', 9, 6)->nullable()->after('taxa_juros_anual');
            $tabela->decimal('cet_anual', 9, 6)->nullable()->after('cet_mensal');
            $tabela->decimal('valor_parcela_contratada', 18, 2)->nullable()->after('quantidade_parcelas');
            $tabela->decimal('total_contratado', 18, 2)->nullable()->after('valor_parcela_contratada');
            $tabela->date('data_contratacao')->nullable()->after('total_contratado');
            $tabela->date('ultimo_vencimento')->nullable()->after('primeiro_vencimento');
            $tabela->index(['usuario_id', 'tipo_divida'], 'emprestimos_usuario_tipo_indice');
        });

        Schema::table('parcelas_emprestimo', function (Blueprint $tabela): void {
            $tabela->decimal('saldo_inicial', 18, 2)->nullable()->after('vencimento');
            $tabela->decimal('saldo_final', 18, 2)->nullable()->after('valor_total');
            $tabela->decimal('valor_pago', 18, 2)->nullable()->after('data_pagamento');
            $tabela->decimal('juros_pago', 18, 2)->nullable()->after('valor_pago');
            $tabela->decimal('amortizacao_paga', 18, 2)->nullable()->after('juros_pago');
            $tabela->foreignId('transacao_pagamento_id')->nullable()->after('transacao_encargos_id')->constrained('transacoes');
        });

        DB::table('emprestimos')->orderBy('id')->each(function (object $emprestimo): void {
            $financiado = bcadd((string) $emprestimo->valor_principal, (string) $emprestimo->taxas_totais, 2);
            $ultimoVencimento = DB::table('parcelas_emprestimo')->where('emprestimo_id', $emprestimo->id)->max('vencimento');
            DB::table('emprestimos')->where('id', $emprestimo->id)->update([
                'valor_recebido' => $emprestimo->valor_principal,
                'valor_financiado' => $financiado,
                'iof' => $emprestimo->taxas_totais,
                'data_contratacao' => $emprestimo->data_recebimento,
                'ultimo_vencimento' => $ultimoVencimento,
            ]);

            $saldo = $financiado;
            DB::table('parcelas_emprestimo')->where('emprestimo_id', $emprestimo->id)->orderBy('numero')->each(
                function (object $parcela) use (&$saldo): void {
                    $amortizacao = bcadd((string) $parcela->principal, (string) $parcela->taxas, 2);
                    $saldoFinal = bccomp($amortizacao, $saldo, 2) >= 0 ? '0.00' : bcsub($saldo, $amortizacao, 2);
                    DB::table('parcelas_emprestimo')->where('id', $parcela->id)->update([
                        'saldo_inicial' => $saldo,
                        'saldo_final' => $saldoFinal,
                        'valor_pago' => $parcela->situacao === 'pendente' ? null : $parcela->valor_total,
                        'juros_pago' => $parcela->situacao === 'pendente' ? null : $parcela->juros,
                        'amortizacao_paga' => $parcela->situacao === 'pendente' ? null : $amortizacao,
                    ]);
                    $saldo = $saldoFinal;
                }
            );
        });
    }

    public function down(): void
    {
        Schema::table('parcelas_emprestimo', function (Blueprint $tabela): void {
            $tabela->dropForeign(['transacao_pagamento_id']);
            $tabela->dropColumn(['saldo_inicial', 'saldo_final', 'valor_pago', 'juros_pago', 'amortizacao_paga', 'transacao_pagamento_id']);
        });
        Schema::table('emprestimos', function (Blueprint $tabela): void {
            $tabela->dropIndex('emprestimos_usuario_tipo_indice');
            $tabela->dropColumn([
                'tipo_divida', 'valor_recebido', 'valor_financiado', 'iof', 'taxa_juros_anual',
                'cet_mensal', 'cet_anual', 'valor_parcela_contratada', 'total_contratado',
                'data_contratacao', 'ultimo_vencimento',
            ]);
        });
    }
};
