<?php

namespace App\Events;

use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Ponto de extensao do fluxo conversacional (Etapa 9A).
 *
 * Disparado depois que as regras deterministicas ja decidiram tudo sobre a
 * mensagem recebida, com ou sem o motor da 9A habilitado. Existe para que
 * camadas posteriores possam observar a avaliacao sem que a 9A precise conhece-las.
 *
 * A 9A nao depende de nenhum ouvinte: sem ouvintes registrados, o disparo e
 * um no-op e o comportamento e exatamente o mesmo.
 */
class ConversationMessageEvaluated
{
    use Dispatchable;

    public function __construct(
        public readonly ConversationMessage $message,
        public readonly ConversationFlowState $state,
        /** Indica se o motor deterministico chegou a processar a mensagem. */
        public readonly bool $flowEngineRan,
        public readonly ?string $blockedReason = null,
    ) {}
}
