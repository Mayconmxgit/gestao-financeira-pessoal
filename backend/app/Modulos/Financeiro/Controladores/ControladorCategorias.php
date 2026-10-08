<?php

namespace App\Modulos\Financeiro\Controladores;

use App\Http\Controllers\Controller;
use App\Modulos\Financeiro\Suporte\EntradaFinanceira as Entrada;
use App\Modulos\Financeiro\Suporte\IdentificadorPublico;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ControladorCategorias extends Controller
{
    public function listar(Request $requisicao): JsonResponse
    {
        $categorias = DB::table('categorias')
            ->where('usuario_id', Entrada::usuario($requisicao))
            ->select('id', 'nome', 'tipo', 'limite_gastos')->orderBy('tipo')->orderBy('nome')->get()
            ->map(fn (object $categoria): object => $this->formatarCategoria($categoria));

        return response()->json(['dados' => $categorias]);
    }

    public function criar(Request $requisicao): JsonResponse
    {
        $dados = Entrada::validar($requisicao, [
            'nome' => ['required', 'string', 'min:2', 'max:80'],
            'tipo' => ['required', 'in:receita,despesa'],
            'limite_gastos' => self::regraLimite(),
        ]);
        $this->validarLimiteDaCategoria($dados['tipo'], $dados['limite_gastos'] ?? null);
        $usuario = Entrada::usuario($requisicao);
        try {
            $id = DB::transaction(function () use ($dados, $usuario): int {
                $id = DB::table('categorias')->insertGetId([
                    'uuid' => IdentificadorPublico::novo(),
                    'usuario_id' => $usuario, 'nome' => trim($dados['nome']), 'tipo' => $dados['tipo'],
                    'limite_gastos' => $this->normalizarLimite($dados['limite_gastos'] ?? null),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                Entrada::auditar($usuario, 'categoria_criada', 'categorias', $id);

                return $id;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['nome' => 'Categoria já cadastrada.']);
        }

        return response()->json($this->buscarCategoriaPublica($usuario, $id), 201);
    }

    public function atualizar(Request $requisicao, int $id): JsonResponse
    {
        $usuario = Entrada::usuario($requisicao);
        $dados = Entrada::validar($requisicao, [
            'nome' => ['sometimes', 'string', 'min:2', 'max:80'],
            'tipo' => ['sometimes', 'in:receita,despesa'],
            'limite_gastos' => ['sometimes', ...self::regraLimite()],
        ]);
        if ($dados === []) {
            throw ValidationException::withMessages(['campos' => 'Informe ao menos uma alteração.']);
        }
        try {
            $this->persistirAtualizacao($usuario, $id, $dados);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['nome' => 'Categoria já cadastrada.']);
        }

        return response()->json($this->buscarCategoriaPublica($usuario, $id));
    }

    private function persistirAtualizacao(int $usuario, int $id, array $dados): void
    {
        DB::transaction(function () use ($usuario, $id, $dados): void {
            $categoria = DB::table('categorias')->where('usuario_id', $usuario)->where('id', $id)
                ->lockForUpdate()->first();
            abort_if($categoria === null, 404);
            $atualizacao = $this->prepararAtualizacao($categoria, $dados);
            $this->validarAlteracaoTipo($id, $categoria->tipo, $atualizacao['tipo'] ?? $categoria->tipo);
            DB::table('categorias')->where('usuario_id', $usuario)->where('id', $id)
                ->update([...$atualizacao, 'updated_at' => now()]);
            $this->auditarAtualizacao($usuario, $id, $categoria, $dados);
        });
    }

    private function auditarAtualizacao(int $usuario, int $id, object $categoria, array $dados): void
    {
        $acao = count($dados) === 1 && array_key_exists('limite_gastos', $dados)
            ? 'limite_categoria_atualizado'
            : 'categoria_atualizada';
        Entrada::auditar($usuario, $acao, 'categorias', $id, [
            'campos' => array_keys($dados),
            'nome_anterior' => $categoria->nome,
            'tipo_anterior' => $categoria->tipo,
            'limite_anterior' => $categoria->limite_gastos,
        ]);
    }

    private function prepararAtualizacao(object $categoria, array $dados): array
    {
        $atualizacao = [];
        if (array_key_exists('nome', $dados)) {
            $atualizacao['nome'] = trim($dados['nome']);
        }
        if (array_key_exists('tipo', $dados)) {
            $atualizacao['tipo'] = $dados['tipo'];
        }
        if (array_key_exists('limite_gastos', $dados)) {
            $atualizacao['limite_gastos'] = $this->normalizarLimite($dados['limite_gastos']);
        }
        $tipo = $atualizacao['tipo'] ?? $categoria->tipo;
        $limite = array_key_exists('limite_gastos', $atualizacao)
            ? $atualizacao['limite_gastos']
            : $this->normalizarLimite($categoria->limite_gastos);
        $this->validarLimiteDaCategoria($tipo, $limite);

        return $atualizacao;
    }

    private function validarAlteracaoTipo(int $categoria, string $tipoAtual, string $tipoNovo): void
    {
        if ($tipoAtual === $tipoNovo) {
            return;
        }
        $possuiVinculos = DB::table('transacoes')->where('categoria_id', $categoria)->exists()
            || DB::table('pagamentos_recorrentes')->where('categoria_id', $categoria)->exists();
        if ($possuiVinculos) {
            throw ValidationException::withMessages([
                'tipo' => 'O tipo não pode ser alterado porque a categoria já possui movimentações vinculadas.',
            ]);
        }
    }

    private static function regraLimite(): array
    {
        return ['nullable', 'numeric', 'gt:0', 'regex:/^[0-9]{1,15}(?:\.[0-9]{1,2})?$/'];
    }

    private function validarLimiteDaCategoria(string $tipo, ?string $limite): void
    {
        if ($tipo !== 'despesa' && $limite !== null) {
            throw ValidationException::withMessages(['limite_gastos' => 'O limite é permitido somente em categorias de despesa.']);
        }
    }

    private function normalizarLimite(?string $limite): ?string
    {
        return $limite === null ? null : Entrada::moeda($limite);
    }

    private function buscarCategoriaPublica(int $usuario, int $id): object
    {
        $categoria = DB::table('categorias')->where('usuario_id', $usuario)->where('id', $id)
            ->select('id', 'nome', 'tipo', 'limite_gastos')->firstOrFail();

        return $this->formatarCategoria($categoria);
    }

    private function formatarCategoria(object $categoria): object
    {
        $categoria->limite_gastos = $categoria->limite_gastos === null
            ? null
            : Entrada::moeda((string) $categoria->limite_gastos);

        return $categoria;
    }
}
