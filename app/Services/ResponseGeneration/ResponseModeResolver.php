<?php

namespace App\Services\ResponseGeneration;

use App\Enums\ResponseGenerationMode;
use App\Models\ConversationFlow;
use App\Services\SystemSettingService;

/**
 * Resolve o modo efetivo de operação.
 *
 * O fluxo so consegue restringir. Desligar globalmente e um botão de parada
 * real: nenhum fluxo consegue continuar gerando.
 */
class ResponseModeResolver
{
    public function __construct(private readonly SystemSettingService $settings) {}

    public function global(): ResponseGenerationMode
    {
        return ResponseGenerationMode::tryFrom((string) $this->settings->get('ai.response.mode', 'disabled'))
            ?? ResponseGenerationMode::Disabled;
    }

    public function forFlow(?ConversationFlow $flow): ResponseGenerationMode
    {
        return ResponseGenerationMode::effective($this->global(), $flow?->response_mode);
    }
}
