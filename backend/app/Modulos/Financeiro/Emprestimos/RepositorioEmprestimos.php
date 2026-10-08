<?php

namespace App\Modulos\Financeiro\Emprestimos;

use App\Modulos\Financeiro\Suporte\IdentificadorPublico;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class RepositorioEmprestimos
{
    public function listar(int $usuario, int $limite = 100): Collection
    {
        return DB::table('emprestimos')->where('usuario_id', $usuario)->orderByDesc('id')->limit($limite)->get();
    }

    public function ativos(int $usuario, ?string $moeda = null): Collection
    {
        return DB::table('emprestimos')->where('usuario_id', $usuario)
            ->whereIn('situacao', ['ativo', 'atrasado'])
            ->when($moeda !== null, fn ($consulta) => $consulta->where('moeda', $moeda))
            ->orderBy('id')->get();
    }

    public function buscar(int $usuario, int $id, bool $bloquear = false): ?object
    {
        $consulta = DB::table('emprestimos')->where('usuario_id', $usuario)->where('id', $id);

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->first();
    }

    public function parcelas(int $emprestimoId, bool $bloquear = false): Collection
    {
        $consulta = DB::table('parcelas_emprestimo')->where('emprestimo_id', $emprestimoId)->orderBy('numero');

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->get();
    }

    public function parcelasDe(Collection $emprestimos): Collection
    {
        return DB::table('parcelas_emprestimo')->whereIn('emprestimo_id', $emprestimos->pluck('id'))->orderBy('numero')->get()->groupBy('emprestimo_id');
    }

    public function parcela(int $emprestimoId, int $parcelaId, bool $bloquear = false): ?object
    {
        $consulta = DB::table('parcelas_emprestimo')->where('emprestimo_id', $emprestimoId)->where('id', $parcelaId);

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->first();
    }

    public function criar(array $dados): int
    {
        return DB::table('emprestimos')->insertGetId(['uuid' => IdentificadorPublico::novo(), ...$dados]);
    }

    public function atualizar(int $id, array $dados): void
    {
        DB::table('emprestimos')->where('id', $id)->update($dados);
    }

    public function atualizarParcela(int $id, array $dados): void
    {
        DB::table('parcelas_emprestimo')->where('id', $id)->update($dados);
    }

    public function atualizarParcelas(Collection $ids, array $dados): void
    {
        DB::table('parcelas_emprestimo')->whereIn('id', $ids)->update($dados);
    }

    public function inserirParcelas(array $parcelas): void
    {
        DB::table('parcelas_emprestimo')->insert(array_map(
            fn (array $parcela): array => ['uuid' => IdentificadorPublico::novo(), ...$parcela],
            $parcelas,
        ));
    }

    public function removerParcelas(int $emprestimoId): void
    {
        DB::table('parcelas_emprestimo')->where('emprestimo_id', $emprestimoId)->delete();
    }

    public function excluir(int $id): void
    {
        DB::table('emprestimos')->where('id', $id)->delete();
    }

    public function criarTransacao(array $dados): int
    {
        return DB::table('transacoes')->insertGetId(['uuid' => IdentificadorPublico::novo(), ...$dados]);
    }

    public function transacao(int $usuario, int $id, bool $bloquear = false): ?object
    {
        $consulta = DB::table('transacoes')->where('usuario_id', $usuario)->where('id', $id);

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->first();
    }

    public function transacaoJaAssociada(int $transacaoId): bool
    {
        return DB::table('parcelas_emprestimo')->where('transacao_pagamento_id', $transacaoId)->exists();
    }

    public function excluirTransacoes(int $usuario, iterable $ids): void
    {
        DB::table('transacoes')->where('usuario_id', $usuario)->whereIn('id', $ids)->delete();
    }

    public function categoriaEncargos(int $usuario): int
    {
        DB::table('categorias')->insertOrIgnore([
            'uuid' => IdentificadorPublico::novo(),
            'usuario_id' => $usuario, 'nome' => 'Juros e taxas', 'tipo' => 'despesa',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('categorias')->where('usuario_id', $usuario)->where('tipo', 'despesa')->where('nome', 'Juros e taxas')->value('id');
    }
}
