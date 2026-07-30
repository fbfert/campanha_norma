<?php

namespace App\Contracts;

use App\Data\Ai\AiCompletionRequest;
use App\Data\Ai\AiCompletionResult;
use App\Exceptions\Ai\AiProviderException;

interface AiProvider
{
    /**
     * Identificador curto do provedor, gravado em `ai_runs.provider`.
     */
    public function name(): string;

    /**
     * Modelo efetivo, já resolvido a partir da configuração.
     */
    public function model(): string;

    /**
     * Executa uma completude com saída estruturada.
     *
     * @throws AiProviderException
     */
    public function complete(AiCompletionRequest $request): AiCompletionResult;
}
