<?php

namespace App\Services\Knowledge\Extractors;

/**
 * Texto extraido, opcionalmente segmentado por página.
 *
 * Quando o formato não fornece página, `pages` fica vazio e o chunker trabalha
 * sobre o texto corrido. Não inventamos número de página: metadado errado numa
 * citação e pior do que metadado ausente.
 */
readonly class ExtractedText
{
    /**
     * @param  array<int, string>  $pages  indexado a partir de 1
     */
    public function __construct(
        public string $text,
        public array $pages = [],
    ) {}

    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }

    public function hasPages(): bool
    {
        return $this->pages !== [];
    }
}
