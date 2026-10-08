<?php

namespace App\Modulos\Financeiro\Importacao;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Smalot\PdfParser\Parser;
use Throwable;

final class ExtratorPdfItau
{
    private const LIMITE_PAGINAS = 100;

    private const LIMITE_TEXTO = 2 * 1024 * 1024;

    private const LIMITE_LINHAS = 5000;

    public function extrair(string $conteudo): array
    {
        if (! str_starts_with($conteudo, '%PDF-')) {
            $this->naoSuportado();
        }

        try {
            $documento = (new Parser)->parseContent($conteudo);
            if (count($documento->getPages()) > self::LIMITE_PAGINAS) {
                $this->naoSuportado('O PDF excede 100 páginas.');
            }
            $texto = $documento->getText();
        } catch (ValidationException $erro) {
            throw $erro;
        } catch (Throwable) {
            $this->naoSuportado('Não foi possível ler o PDF.');
        }

        if (strlen($texto) > self::LIMITE_TEXTO) {
            $this->naoSuportado('O conteúdo extraído do PDF é muito grande.');
        }

        return $this->extrairTexto($texto);
    }

    public function extrairTexto(string $texto): array
    {
        if (! $this->layoutReconhecido($texto)) {
            $this->naoSuportado('PDF bancário sem o layout reconhecido do Itaú.');
        }

        $linhasTexto = array_values(array_filter(
            preg_split('/\R/u', $texto) ?: [],
            fn (string $linha) => preg_match('/^\s*\d{2}\/\d{2}\/\d{4}/u', $linha) === 1,
        ));
        if (count($linhasTexto) > self::LIMITE_LINHAS) {
            $this->naoSuportado('O arquivo excede 5000 linhas.');
        }
        $linhas = array_values(array_filter(
            array_map(fn (string $linha) => $this->extrairLinha($linha), $linhasTexto),
            fn (?array $linha) => $linha !== null,
        ));

        if ($linhas === []) {
            $this->naoSuportado('Nenhum lançamento foi encontrado no PDF.');
        }

        return $linhas;
    }

    private function extrairLinha(string $texto): ?array
    {
        if (! preg_match('/^\s*(\d{2}\/\d{2}\/\d{4})(.+?)\s+(-?\d{1,3}(?:\.\d{3})*,\d{2})\s*$/u', $texto, $partes)) {
            return ['data' => mb_substr(trim($texto), 0, 10), 'descricao' => mb_substr(trim($texto), 10), 'valor' => null];
        }

        $descricao = trim($partes[2]);
        if (mb_strtolower(Str::ascii($descricao)) === 'saldo do dia') {
            return null;
        }

        return ['data' => $partes[1], 'descricao' => $descricao, 'valor' => $partes[3]];
    }

    private function layoutReconhecido(string $texto): bool
    {
        $normalizado = mb_strtolower(Str::ascii($texto));
        $normalizado = preg_replace('/\s+/u', ' ', $normalizado) ?? '';

        return str_contains($normalizado, 'extrato conta / lancamentos')
            && str_contains($normalizado, 'valor (r$)')
            && str_contains($normalizado, 'saldo (r$)');
    }

    private function naoSuportado(?string $detalhe = null): never
    {
        $mensagem = 'ARQUIVO_NAO_SUPORTADO';
        if ($detalhe !== null) {
            $mensagem .= ': '.$detalhe;
        }

        throw ValidationException::withMessages(['arquivo' => $mensagem]);
    }
}
