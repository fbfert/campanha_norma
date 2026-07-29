<?php

namespace App\Services\Ai\Providers;

use App\Contracts\AiProvider;
use App\Data\Ai\AiCompletionRequest;
use App\Data\Ai\AiCompletionResult;
use App\Exceptions\Ai\AiProviderException;

/**
 * Provedor inerte, ativo quando nenhum fornecedor esta configurado.
 *
 * Falha de forma controlada e sem qualquer chamada de rede, para que a ausencia
 * de configuracao nunca produza um efeito silencioso nem uma tentativa externa.
 */
class NullAiProvider implements AiProvider
{
    public function name(): string
    {
        return 'null';
    }

    public function model(): string
    {
        return 'none';
    }

    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        throw new AiProviderException(
            AiProviderException::NOT_CONFIGURED,
            'Nenhum provedor de IA configurado.'
        );
    }
}
