<?php

namespace App\Services\Knowledge\Providers;

use App\Contracts\KnowledgeBaseProvider;
use App\Data\Knowledge\ProviderIndexResult;
use App\Exceptions\Knowledge\KnowledgeProviderException;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;

/**
 * Provedor inerte, ativo quando nenhum armazenamento esta configurado.
 *
 * Indexar falha de forma controlada; remover e criar armazenamento nao fazem
 * nada. A camada pode estar instalada e desligada sem efeito silencioso.
 */
class NullKnowledgeBaseProvider implements KnowledgeBaseProvider
{
    public function name(): string
    {
        return 'null';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function supportsRemoteStore(): bool
    {
        return false;
    }

    public function createStore(KnowledgeBase $base): ?string
    {
        return null;
    }

    public function deleteStore(KnowledgeBase $base): void
    {
        // Nada a remover.
    }

    public function indexDocument(KnowledgeDocument $document, array $chunks): ProviderIndexResult
    {
        throw KnowledgeProviderException::code(KnowledgeProviderException::NOT_CONFIGURED);
    }

    public function deleteDocument(KnowledgeDocument $document): void
    {
        // Nada a remover.
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        return [
            'provider' => 'null',
            'configured' => false,
            'healthy' => false,
            'detail' => 'Nenhum provedor de base de conhecimento configurado.',
        ];
    }
}
