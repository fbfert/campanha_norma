<?php

namespace App\Listeners;

use App\Events\ConversationMessageEvaluated;
use App\Jobs\InterpretConversationMessageJob;
use App\Services\Ai\AiInterpretationGuard;

/**
 * Ponte da Etapa 9B sobre o ponto de extensao da 9A.
 *
 * A decisao de interpretar depende apenas das chaves de IA. O motor de fluxo da
 * 9A pode estar ligado ou desligado: o que a 9B exige e contexto valido de
 * pesquisa, garantido pela existencia do estado de fluxo no evento.
 */
class DispatchConversationInterpretation
{
    public function __construct(private readonly AiInterpretationGuard $guard) {}

    public function handle(ConversationMessageEvaluated $event): void
    {
        if (! $this->guard->analysisEnabled()) {
            return;
        }

        InterpretConversationMessageJob::dispatch($event->message->id);
    }
}
