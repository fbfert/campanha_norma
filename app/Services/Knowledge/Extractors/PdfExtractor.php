<?php

namespace App\Services\Knowledge\Extractors;

use App\Exceptions\Knowledge\KnowledgeProviderException;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * PDF textual, por binario externo configuravel.
 *
 * Nao existe fallback nativo, e isso e uma decisao e nao uma lacuna. Um extrator
 * improvisado de streams de PDF produz texto parcialmente correto, e texto
 * parcialmente correto dentro de uma base oficial e pior do que uma falha limpa:
 * a falha alguem conserta, o texto corrompido alguem cita.
 *
 * Quando o binario nao existe, o documento vai para `failed` com o codigo
 * `extrator_pdf_indisponivel`. Instalar com `dnf install poppler-utils`.
 */
class PdfExtractor implements TextExtractor
{
    public function supports(string $mimeType, string $extension): bool
    {
        return $mimeType === 'application/pdf' || $extension === 'pdf';
    }

    public function extract(string $path): ExtractedText
    {
        $arguments = $this->arguments($path);

        try {
            $process = new Process($arguments);
            $process->setTimeout((float) config('knowledge.process_timeout'));
            $process->run();
        } catch (ExceptionInterface $exception) {
            throw KnowledgeProviderException::code(
                KnowledgeProviderException::PDF_EXTRACTOR_UNAVAILABLE,
                $exception::class,
            );
        }

        if (! $process->isSuccessful()) {
            throw KnowledgeProviderException::code(
                KnowledgeProviderException::EMPTY_EXTRACTION,
                'Codigo de saida '.$process->getExitCode().'.',
            );
        }

        $output = $process->getOutput();

        if (trim($output) === '') {
            // PDF de imagem escaneada cai aqui. Sem OCR, nao ha texto a indexar.
            throw KnowledgeProviderException::code(
                KnowledgeProviderException::EMPTY_EXTRACTION,
                'PDF sem camada de texto.',
            );
        }

        return $this->paginate($output);
    }

    /**
     * Monta os argumentos sem passar por shell.
     *
     * O template vem de configuracao e o caminho do arquivo entra como argumento
     * separado, nunca interpolado numa linha de comando: um nome de arquivo
     * hostil nao tem como virar comando.
     *
     * @return array<int, string>
     */
    private function arguments(string $path): array
    {
        $template = trim((string) config('knowledge.pdf_text_command'));

        if ($template === '') {
            throw KnowledgeProviderException::code(KnowledgeProviderException::PDF_EXTRACTOR_UNAVAILABLE);
        }

        $parts = preg_split('/\s+/', $template) ?: [];
        $binary = array_shift($parts);

        if (! is_string($binary) || $binary === '') {
            throw KnowledgeProviderException::code(KnowledgeProviderException::PDF_EXTRACTOR_UNAVAILABLE);
        }

        $resolved = str_contains($binary, '/') ? $binary : (new ExecutableFinder)->find($binary);

        if (! is_string($resolved) || $resolved === '' || ! is_executable($resolved)) {
            throw KnowledgeProviderException::code(
                KnowledgeProviderException::PDF_EXTRACTOR_UNAVAILABLE,
                'Binario nao encontrado: '.$binary,
            );
        }

        $arguments = [$resolved];

        foreach ($parts as $part) {
            $arguments[] = $part === ':input' ? $path : $part;
        }

        return $arguments;
    }

    /**
     * `pdftotext` separa paginas com form feed. Quando o separador nao aparece,
     * devolvemos texto corrido em vez de fingir uma paginacao que nao existe.
     */
    private function paginate(string $output): ExtractedText
    {
        $output = str_replace(["\r\n", "\r"], "\n", $output);

        if (! str_contains($output, "\f")) {
            return new ExtractedText(trim($output));
        }

        $pages = [];
        $number = 1;

        foreach (explode("\f", $output) as $raw) {
            $page = trim((string) preg_replace("/\n{3,}/", "\n\n", $raw));

            if ($page !== '') {
                $pages[$number] = $page;
            }

            $number++;
        }

        return new ExtractedText(trim(implode("\n\n", $pages)), $pages);
    }
}
