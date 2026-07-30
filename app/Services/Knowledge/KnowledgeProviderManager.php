<?php

namespace App\Services\Knowledge;

use App\Contracts\EmbeddingProvider;
use App\Contracts\KnowledgeBaseProvider;
use App\Services\Knowledge\Embeddings\NullEmbeddingProvider;
use App\Services\Knowledge\Embeddings\OpenAiCompatibleEmbeddingProvider;
use App\Services\Knowledge\Providers\LocalKnowledgeBaseProvider;
use App\Services\Knowledge\Providers\NullKnowledgeBaseProvider;

class KnowledgeProviderManager
{
    public function provider(): KnowledgeBaseProvider
    {
        return match ((string) config('knowledge.provider')) {
            'local' => app(LocalKnowledgeBaseProvider::class),
            // Fornecedor desconhecido nunca vira erro fatal em producao: cai no
            // provedor inerte, que falha de forma controlada e auditavel.
            default => app(NullKnowledgeBaseProvider::class),
        };
    }

    public function embeddings(): EmbeddingProvider
    {
        return match ((string) config('knowledge.embeddings.provider')) {
            'openai' => app(OpenAiCompatibleEmbeddingProvider::class),
            default => app(NullEmbeddingProvider::class),
        };
    }
}
