<?php

namespace App\Services\Knowledge\Extractors;

/**
 * Texto extraido, opcionalmente segmentado por pagina.
 *
 * Quando o formato nao fornece pagina, `pages` fica vazio e o chunker trabalha
 * sobre o texto corrido. Nao inventamos numero de pagina: metadado errado numa
 * citacao e pior do que metadado ausente.
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
