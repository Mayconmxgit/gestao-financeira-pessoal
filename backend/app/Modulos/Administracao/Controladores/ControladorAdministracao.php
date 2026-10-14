<?php

namespace App\Modulos\Administracao\Controladores;

use App\Http\Controllers\Controller;
use App\Modulos\Administracao\Servicos\ServicoMetricasAdministrativas;
use App\Modulos\Administracao\Servicos\ServicoUsuariosAdministrativos;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ControladorAdministracao extends Controller
{
    private const RELATORIOS = ['crescimento', 'receita-recorrente', 'churn', 'operacoes'];

    private const SITUACOES = ['criando', 'pendente', 'ativa', 'periodo_graca', 'inadimplente', 'pausada', 'cancelada', 'falha'];

    public function dashboard(Request $requisicao, ServicoMetricasAdministrativas $servico): JsonResponse
    {
        $filtros = $this->periodo($requisicao);
        $chave = 'admin:dashboard:'.hash('sha256', json_encode($filtros, JSON_THROW_ON_ERROR));

        return response()->json(Cache::remember($chave, 60, fn () => $servico->dashboard(
            $filtros['intervalo'], $filtros['referencia'], $filtros['de'] ?? null, $filtros['ate'] ?? null
        )));
    }

    public function usuarios(Request $requisicao, ServicoUsuariosAdministrativos $servico): JsonResponse
    {
        return response()->json($servico->listar($this->filtrosUsuarios($requisicao)));
    }

    public function exportarUsuarios(Request $requisicao, ServicoUsuariosAdministrativos $servico): StreamedResponse
    {
        $filtros = $this->filtrosUsuarios($requisicao, exportacao: true);

        return response()->streamDownload(function () use ($servico, $filtros): void {
            $arquivo = fopen('php://output', 'wb');
            fputcsv($arquivo, ['nome', 'email', 'cadastro', 'plano', 'situacao', 'origem'], ';');
            $cursor = null;
            do {
                $pagina = $servico->listar([...$filtros, 'limite' => 100, 'apos' => $cursor]);
                foreach ($pagina['dados'] as $usuario) {
                    fputcsv($arquivo, array_map(fn ($valor) => $this->celulaCsv($valor), [
                        $usuario['nome'], $usuario['email'], $usuario['criado_em'],
                        $usuario['assinatura']['plano'] ?? null, $usuario['assinatura']['situacao'] ?? null,
                        $usuario['assinatura']['provedor'] ?? null,
                    ]), ';');
                }
                $cursor = $pagina['proximo_cursor'];
            } while ($cursor !== null);
            fclose($arquivo);
        }, 'usuarios.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function relatorio(Request $requisicao, string $tipo, ServicoMetricasAdministrativas $servico): JsonResponse
    {
        abort_unless(in_array($tipo, self::RELATORIOS, true), 404);
        $filtros = $this->periodo($requisicao);
        $chave = 'admin:relatorio:'.$tipo.':'.hash('sha256', json_encode($filtros, JSON_THROW_ON_ERROR));

        return response()->json(Cache::remember($chave, 60, fn () => $servico->relatorio(
            $tipo, $filtros['intervalo'], $filtros['referencia'], $filtros['de'] ?? null, $filtros['ate'] ?? null
        )));
    }

    public function exportar(Request $requisicao, string $tipo, ServicoMetricasAdministrativas $servico): StreamedResponse
    {
        abort_unless(in_array($tipo, self::RELATORIOS, true), 404);
        $filtros = $this->periodo($requisicao);
        $relatorio = $servico->relatorio(
            $tipo, $filtros['intervalo'], $filtros['referencia'], $filtros['de'] ?? null, $filtros['ate'] ?? null
        );
        $linhas = $relatorio['tabela'] ?: $relatorio['serie'];

        return response()->streamDownload(function () use ($linhas): void {
            $arquivo = fopen('php://output', 'wb');
            if ($linhas === []) {
                fputcsv($arquivo, ['Sem dados'], ';');
                fclose($arquivo);

                return;
            }
            fputcsv($arquivo, array_keys($linhas[0]), ';');
            foreach ($linhas as $linha) {
                fputcsv($arquivo, array_map(fn ($valor) => $this->celulaCsv($valor), $linha), ';');
            }
            fclose($arquivo);
        }, "relatorio-{$tipo}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function operacoes(Request $requisicao, ServicoMetricasAdministrativas $servico): JsonResponse
    {
        $dados = $this->validarConsulta($requisicao, ['limite' => ['nullable', 'integer', 'between:1,100']]);

        return response()->json($servico->operacoes($dados['limite'] ?? 50));
    }

    private function periodo(Request $requisicao): array
    {
        $dados = $this->validarConsulta($requisicao, [
            'intervalo' => ['nullable', Rule::in(['7d', '30d', '90d', '12m', 'personalizado'])],
            'referencia' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'de' => ['nullable', 'required_if:intervalo,personalizado', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'required_if:intervalo,personalizado', 'date_format:Y-m-d', 'after_or_equal:de', 'before_or_equal:today'],
        ]);

        if (($dados['intervalo'] ?? null) === 'personalizado'
            && CarbonImmutable::parse($dados['ate'])->greaterThan(CarbonImmutable::parse($dados['de'])->addMonthsNoOverflow(24))) {
            throw ValidationException::withMessages(['ate' => 'O período personalizado deve ter no máximo 24 meses.']);
        }

        return [...$dados, 'intervalo' => $dados['intervalo'] ?? '30d', 'referencia' => $dados['referencia'] ?? now()->toDateString()];
    }

    private function validarConsulta(Request $requisicao, array $regras): array
    {
        if (array_diff(array_keys($requisicao->query()), array_keys($regras))) {
            throw ValidationException::withMessages(['campos' => 'A consulta contém campos não permitidos.']);
        }

        return Validator::make($requisicao->query(), $regras)->validate();
    }

    private function filtrosUsuarios(Request $requisicao, bool $exportacao = false): array
    {
        $regras = [
            'busca' => ['nullable', 'string', 'min:2', 'max:100'], 'situacao' => ['nullable', Rule::in(self::SITUACOES)],
            'plano_id' => ['nullable', 'uuid'], 'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:de'],
        ];
        if (! $exportacao) {
            $regras['apos'] = ['nullable', 'uuid'];
            $regras['limite'] = ['nullable', 'integer', 'between:1,100'];
        }

        return $this->validarConsulta($requisicao, $regras);
    }

    private function celulaCsv(mixed $valor): string
    {
        $texto = $valor === null ? 'Sem base' : (string) $valor;

        return preg_match('/^[=+\-@]/u', $texto) === 1 ? "'{$texto}" : $texto;
    }
}
