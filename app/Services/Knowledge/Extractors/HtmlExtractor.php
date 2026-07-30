<?php

namespace App\Services\Knowledge\Extractors;

use App\Exceptions\Knowledge\KnowledgeProviderException;
use DOMDocument;
use DOMNode;
use DOMXPath;

/**
 * HTML.
 *
 * `script`, `style`, `noscript` e comentarios sao removidos antes da leitura:
 * codigo e comentario nao sao conteudo aprovado, e comentario HTML e um lugar
 * classico para esconder instrucao de injecao.
 */
class HtmlExtractor implements TextExtractor
{
    public function supports(string $mimeType, string $extension): bool
    {
        return in_array($mimeType, ['text/html', 'application/xhtml+xml'], true)
            || in_array($extension, ['html', 'htm'], true);
    }

    public function extract(string $path): ExtractedText
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw KnowledgeProviderException::code(KnowledgeProviderException::FILE_MISSING);
        }

        $document = new DOMDocument;

        // HTML real e malformado com frequencia. Silenciamos os avisos do parser
        // e trabalhamos com o que ele conseguiu montar.
        $loaded = @$document->loadHTML(
            '<?xml encoding="UTF-8">'.$contents,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
        );

        if ($loaded === false) {
            throw KnowledgeProviderException::code(KnowledgeProviderException::EMPTY_EXTRACTION);
        }

        $xpath = new DOMXPath($document);
        $removable = $xpath->query('//script | //style | //noscript | //comment()');

        if ($removable !== false) {
            foreach (iterator_to_array($removable) as $node) {
                /** @var DOMNode $node */
                $node->parentNode?->removeChild($node);
            }
        }

        $text = (string) $document->textContent;
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[ \t]{2,}/', ' ', $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return new ExtractedText(trim($text));
    }
}
