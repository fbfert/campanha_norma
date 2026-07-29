<?php

namespace App\Services\Ai;

use App\Contracts\AiProvider;
use App\Services\Ai\Providers\NullAiProvider;
use App\Services\Ai\Providers\OpenAiCompatibleProvider;

class AiProviderManager
{
    public function provider(): AiProvider
    {
        return match ((string) config('ai.provider')) {
            'openai' => app(OpenAiCompatibleProvider::class),
            // Fornecedor desconhecido nunca vira erro fatal em producao: cai no
            // provedor inerte, que falha de forma controlada e auditavel.
            default => app(NullAiProvider::class),
        };
    }
}
