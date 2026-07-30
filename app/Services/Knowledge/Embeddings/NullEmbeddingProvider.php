<?php

namespace App\Services\Knowledge\Embeddings;

use App\Contracts\EmbeddingProvider;
use App\Exceptions\Knowledge\KnowledgeProviderException;

/**
 * Provedor de embeddings inerte.
 *
 * Ausencia de embeddings nao impede a base de funcionar: a estrategia lexica e a
 * padrao justamente para que a base seja utilizavel e homologavel sem credencial.
 */
class NullEmbeddingProvider implements EmbeddingProvider
{
    public function name(): string
    {
        return 'null';
    }

    public function model(): string
    {
        return 'none';
    }

    public function dimensions(): int
    {
        return 0;
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function embed(array $texts): array
    {
        throw KnowledgeProviderException::code(KnowledgeProviderException::EMBEDDINGS_NOT_CONFIGURED);
    }
}
