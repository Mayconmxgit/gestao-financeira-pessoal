<?php

namespace App\Modulos\Financeiro\Suporte;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class EntradaFinanceira
{
    public static function validar(Request $requisicao, array $regras): array
    {
        $dados = $requisicao->all();
        if (array_diff(array_keys($dados), array_keys($regras))) {
            throw ValidationException::withMessages(['campos' => 'A requisição contém campos não permitidos.']);
        }

        return Validator::make($dados, $regras)->validate();
    }

    public static function usuario(Request $requisicao): int
    {
        return (int) $requisicao->attributes->get('usuario_atual')->id;
    }

    public static function conta(int $usuario, int $conta): object
    {
        $registro = DB::table('contas')->where('usuario_id', $usuario)->where('id', $conta)->first();
        abort_if($registro === null, 404);

        return $registro;
    }

    public static function categoria(int $usuario, ?int $categoria): void
    {
        if ($categoria !== null) {
            abort_unless(DB::table('categorias')->where('usuario_id', $usuario)->where('id', $categoria)->exists(), 422);
        }
    }

    public static function auditar(int $usuario, string $acao, string $entidade, int $id, array $detalhes = []): void
    {
        DB::table('auditoria_financeira')->insert([
            'usuario_id' => $usuario,
            'acao' => $acao,
            'entidade' => $entidade,
            'entidade_id' => $id,
            'detalhes' => json_encode($detalhes, JSON_THROW_ON_ERROR),
            'criado_em' => now(),
        ]);
    }

    public static function moeda(string $valor): string
    {
        return bcadd($valor, '0', 2);
    }

    public static function valorValido(): array
    {
        return ['required', 'regex:/^-?[0-9]{1,15}(?:\.[0-9]{1,2})?$/'];
    }
}
