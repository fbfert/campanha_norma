<?php

namespace App\Listeners;

use App\Events\ConversationMessageEvaluated;
use App\Jobs\GenerateConversationReplyJob;
use App\Services\ResponseGeneration\ResponseModeResolver;
use App\Services\SystemSettingService;

/**
 * Ponte da Etapa 9C sobre o ponto de extensão da 9A.
 *
 * O atraso configurável agrupa mensagens consecutivas antes de gerar.
 */
class DispatchConversationReplyGeneration
{
    public function __construct(
        private readonly ResponseModeResolver $modes,
        private readonly SystemSettingService $settings,
    ) {}

    public function handle(ConversationMessageEvaluated $event): void
    {
        if (! $this->modes->forFlow($event->state->flow)->generates()) {
            return;
        }

        $delay = max(0, (int) $this->settings->get('ai.response.debounce_seconds', 20));

        GenerateConversationReplyJob::dispatch($event->message->id)->delay(now()->addSeconds($delay));
    }
}
