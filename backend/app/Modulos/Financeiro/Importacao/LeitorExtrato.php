<?php

namespace App\Modulos\Financeiro\Importacao;

use DateTimeImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LeitorExtrato
{
    public function ler(string $conteudo, string $formato): array
    {
        if ($formato === 'pdf') {
            return $this->pdf($conteudo);
        }

        $semBom = str_starts_with($conteudo, "\xEF\xBB\xBF") ? substr($conteudo, 3) : $conteudo;
        $texto = mb_check_encoding($semBom, 'UTF-8')
            ? $semBom : mb_convert_encoding($semBom, 'UTF-8', 'Windows-1252');

        return match ($formato) {
            'csv' => $this->csv($texto),
            'ofx' => $this->ofx($texto),
            default => throw ValidationException::withMessages(['arquivo' => 'ARQUIVO_NAO_SUPORTADO']),
        };
    }

    private function pdf(string $conteudo): array
    {
        $linhas = [];
        foreach ((new ExtratorPdfItau)->extrair($conteudo) as $indice => $linha) {
            $linhas[] = $this->normalizar(
                $indice + 1, $linha['data'], $linha['valor'], $linha['descricao'], null, null,
            );
        }

        return ['adaptador' => 'pdf_itau', 'versao' => 1, 'instituicao' => 'Itaú', 'linhas' => $linhas];
    }

    private function csv(string $texto): array
    {
        $arquivo = fopen('php://temp', 'w+');
        fwrite($arquivo, $texto);
        rewind($arquivo);
        $primeira = fgets($arquivo);
        rewind($arquivo);
        $delimitador = substr_count((string) $primeira, ';') >= substr_count((string) $primeira, ',') ? ';' : ',';
        $cabecalho = fgetcsv($arquivo, 0, $delimitador);
        if (! is_array($cabecalho)) {
            throw ValidationException::withMessages(['arquivo' => 'ARQUIVO_NAO_SUPORTADO']);
        }
        $cabecalho = array_map(fn ($nome) => $this->cabecalho((string) $nome), $cabecalho);
        $campos = [
            'data' => ['data', 'data_lancamento', 'date'],
            'descricao' => ['descricao', 'historico', 'lancamento', 'lancamentos', 'description', 'memo'],
            'valor' => ['valor', 'amount', 'valor_r'],
            'identificador' => ['id_bancario', 'fitid', 'identificador'],
            'documento' => ['documento', 'referencia', 'refnum'],
        ];
        $indices = [];
        foreach ($campos as $campo => $nomes) {
            foreach ($nomes as $nome) {
                $posicao = array_search($nome, $cabecalho, true);
                if ($posicao !== false) {
                    $indices[$campo] = $posicao;
                    break;
                }
            }
        }
        if (count(array_unique($cabecalho)) !== count($cabecalho) || array_diff(['data', 'descricao', 'valor'], array_keys($indices))) {
            throw ValidationException::withMessages(['arquivo' => 'ARQUIVO_NAO_SUPORTADO: cabeçalho CSV inválido.']);
        }
        $linhas = [];
        while (($colunas = fgetcsv($arquivo, 0, $delimitador)) !== false) {
            if (count($linhas) >= 5000) {
                throw ValidationException::withMessages(['arquivo' => 'O arquivo excede 5000 linhas.']);
            }
            if (count($colunas) === 1 && trim((string) $colunas[0]) === '') {
                continue;
            }
            $valor = fn (string $campo) => isset($indices[$campo]) ? trim((string) ($colunas[$indices[$campo]] ?? '')) : null;
            if ($this->linhaInformativa($valor('descricao'), $valor('valor'))) {
                continue;
            }
            $linhas[] = $this->normalizar(
                count($linhas) + 1, $valor('data'), $valor('valor'),
                $valor('descricao'), $valor('identificador'), $valor('documento'),
            );
        }
        fclose($arquivo);

        return ['adaptador' => 'csv_padrao', 'versao' => 2, 'instituicao' => null, 'linhas' => $linhas];
    }

    private function linhaInformativa(?string $descricao, ?string $valor): bool
    {
        if (trim((string) $valor) !== '') {
            return false;
        }
        $rotulo = mb_strtolower(Str::ascii(trim((string) $descricao)));

        return in_array($rotulo, ['saldo anterior', 'saldo do dia', 'saldo final'], true);
    }

    private function cabecalho(string $nome): string
    {
        $semAcentos = mb_strtolower(Str::ascii(trim($nome, "\xEF\xBB\xBF \t\r\n")));

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $semAcentos), '_');
    }

    private function ofx(string $texto): array
    {
        if (! preg_match('/<OFX\b/i', $texto)) {
            throw ValidationException::withMessages(['arquivo' => 'ARQUIVO_NAO_SUPORTADO']);
        }
        preg_match_all('/<STMTTRN>(.*?)(?=<\/STMTTRN>|<STMTTRN>|<\/BANKTRANLIST>)/is', $texto, $blocos);
        if (count($blocos[1]) === 0 || count($blocos[1]) > 5000) {
            throw ValidationException::withMessages(['arquivo' => 'ARQUIVO_NAO_SUPORTADO: quantidade de lançamentos inválida.']);
        }
        $linhas = [];
        foreach ($blocos[1] as $indice => $bloco) {
            $linhas[] = $this->normalizar(
                $indice + 1, substr((string) $this->tag($bloco, 'DTPOSTED'), 0, 8),
                $this->tag($bloco, 'TRNAMT'),
                $this->tag($bloco, 'NAME') ?? $this->tag($bloco, 'MEMO'),
                $this->tag($bloco, 'FITID'), $this->tag($bloco, 'CHECKNUM'),
            );
        }

        return ['adaptador' => 'ofx_padrao', 'versao' => 1, 'instituicao' => $this->tag($texto, 'BANKID'), 'linhas' => $linhas];
    }

    private function tag(string $texto, string $nome): ?string
    {
        if (! preg_match('/<'.preg_quote($nome, '/').'>\s*([^<\r\n]+)/i', $texto, $resultado)) {
            return null;
        }

        return trim(html_entity_decode($resultado[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function normalizar(int $numero, ?string $data, ?string $valor, ?string $descricao, ?string $identificador, ?string $documento): array
    {
        $dataNormalizada = $this->data((string) $data);
        $valorNormalizado = $this->valor((string) $valor);
        $descricao = trim((string) $descricao);
        $erro = null;
        if ($dataNormalizada === null || $valorNormalizado === null || $descricao === '' || mb_strlen($descricao) > 500) {
            $erro = 'LINHA_INVALIDA';
        }

        return [
            'numero' => $numero, 'data_efetiva' => $dataNormalizada,
            'valor' => $valorNormalizado, 'descricao_original' => mb_substr($descricao, 0, 500),
            'identificador_bancario' => $identificador ? mb_substr($identificador, 0, 150) : null,
            'documento' => $documento ? mb_substr($documento, 0, 120) : null,
            'erro' => $erro,
        ];
    }

    private function data(string $texto): ?string
    {
        foreach (['!Ymd', '!Y-m-d', '!d/m/Y'] as $formato) {
            $data = DateTimeImmutable::createFromFormat($formato, trim($texto));
            if ($data !== false && $data->format(substr($formato, 1)) === trim($texto)) {
                return $data->format('Y-m-d');
            }
        }

        return null;
    }

    private function valor(string $texto): ?string
    {
        $texto = trim(str_replace(['R$', ' '], '', $texto));
        if (preg_match('/^-?\d{1,3}(?:\.\d{3})*,\d{1,2}$/', $texto)) {
            $texto = str_replace(',', '.', str_replace('.', '', $texto));
        } elseif (preg_match('/^-?\d+,\d{1,2}$/', $texto)) {
            $texto = str_replace(',', '.', $texto);
        }
        if (! preg_match('/^-?\d{1,15}(?:\.\d{1,2})?$/', $texto) || bccomp($texto, '0', 2) === 0) {
            return null;
        }

        return bcadd($texto, '0', 2);
    }
}
