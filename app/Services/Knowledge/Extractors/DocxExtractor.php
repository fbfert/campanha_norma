<?php

namespace App\Services\Knowledge\Extractors;

use App\Exceptions\Knowledge\KnowledgeProviderException;
use ZipArchive;

/**
 * DOCX.
 *
 * Um DOCX e um ZIP com XML dentro. `ZipArchive` esta presente no ambiente, então
 * a extração não precisa de nenhuma dependência nova: lemos
 * `word/document.xml`, transformamos fim de paragrafo e quebra de linha em
 * newline e removemos o restante das tags.
 */
class DocxExtractor implements TextExtractor
{
    public function supports(string $mimeType, string $extension): bool
    {
        return $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            || $extension === 'docx';
    }

    public function extract(string $path): ExtractedText
    {
        if (! class_exists(ZipArchive::class)) {
            throw KnowledgeProviderException::code(KnowledgeProviderException::EXTRACTOR_UNAVAILABLE, 'ZipArchive ausente.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw KnowledgeProviderException::code(KnowledgeProviderException::INVALID_RESPONSE, 'DOCX ilegível.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (! is_string($xml) || $xml === '') {
            throw KnowledgeProviderException::code(KnowledgeProviderException::EMPTY_EXTRACTION);
        }

        // Estrutura de paragrafo e quebra viram newline antes de as tags cairem,
        // senão o documento inteiro colapsa em uma linha única.
        $xml = preg_replace('/<w:(p|br|tab)\b[^>]*\/?>/', "\n", $xml) ?? $xml;
        $xml = str_replace('</w:p>', "\n", $xml);

        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t]{2,}/', ' ', $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return new ExtractedText(trim($text));
    }
}
