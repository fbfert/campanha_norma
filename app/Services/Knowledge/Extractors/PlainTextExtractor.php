<?php

namespace App\Services\Knowledge\Extractors;

use App\Exceptions\Knowledge\KnowledgeProviderException;

/**
 * Texto plano e Markdown.
 *
 * Markdown e tratado como texto: os marcadores de título servem depois para
 * identificar seção no chunker, então removelos aqui perderia informação.
 */
class PlainTextExtractor implements TextExtractor
{
    public function supports(string $mimeType, string $extension): bool
    {
        return in_array($mimeType, ['text/plain', 'text/markdown', 'text/x-markdown'], true)
            || in_array($extension, ['txt', 'md', 'markdown'], true);
    }

    public function extract(string $path): ExtractedText
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw KnowledgeProviderException::code(KnowledgeProviderException::FILE_MISSING);
        }

        return new ExtractedText($this->normalize($contents));
    }

    private function normalize(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $converted = @mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
            $text = is_string($converted) ? $converted : $text;
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
