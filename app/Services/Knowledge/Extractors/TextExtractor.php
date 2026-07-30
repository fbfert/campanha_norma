<?php

namespace App\Services\Knowledge\Extractors;

use App\Exceptions\Knowledge\KnowledgeProviderException;

interface TextExtractor
{
    /**
     * @param  string  $path  caminho absoluto do arquivo privado
     */
    public function supports(string $mimeType, string $extension): bool;

    /**
     * @throws KnowledgeProviderException
     */
    public function extract(string $path): ExtractedText;
}
