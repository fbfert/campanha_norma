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
     * Modelo efetivo, ja resolvido a partir da configuracao.
     */
    public function model(): string;

    /**
     * Executa uma completude com saida estruturada.
     *
     * @throws AiProviderException
     */
    public function complete(AiCompletionRequest $request): AiCompletionResult;
}
