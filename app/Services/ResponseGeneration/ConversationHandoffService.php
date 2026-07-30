<?php

namespace App\Services\ResponseGeneration;

use App\Enums\ConversationFlowStage;
use App\Enums\ConversationPriority;
use App\Enums\HandoffReason;
use App\Enums\ReplySuggestionStatus;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\ConversationReplySuggestion;
use App\Services\AuditLogger;
use App\Services\ConversationAutomation\ConversationFlowStateMachine;
use App\Services\Conversations\ConversationEventService;

/**
 * Encaminhamento para atendimento humano.
 *
 * Pausa a automacao, muda o estado, eleva a prioridade quando cabe, cria evento
 * e registra o motivo. Nunca envia texto improvisado.
 */
class ConversationHandoffService
{
    public function __construct(
        private readonly ConversationFlowStateMachine $machine,
        private readonly ConversationEventService $events,
        private readonly AuditLogger $audit,
    ) {}

    public function handoff(
        ConversationFlowState $state,
        HandoffReason $reason,
        ?ConversationMessage $message = null,
    ): void {
        $state->forceFill([
            'is_paused' => true,
            'needs_human_review' => true,
        ])->save();

        // Nao reabre fluxo terminal: apenas marca a necessidade de atendimento.
        if (! $state->current_stage->isTerminal()) {
            $this->machine->transition(
                $state,
                ConversationFlowStage::WaitingHuman,
                'handoff_to_human',
                $message,
                $reason->value,
            );
        }

        $conversation = $state->conversation;

        if ($conversation) {
            if ($reason->raisesPriority() && $conversation->priority !== ConversationPriority::Urgent) {
                $conversation->forceFill(['priority' => ConversationPriority::High])->save();
            }

            $this->events->record(
                $conversation,
                'automation_handoff',
                'Conversa encaminhada para atendimento humano.',
                $message,
                null,
                ['reason' => $reason->value, 'reason_label' => $reason->label()],
            );
        }

        // Sugestoes vivas perdem validade: nada pendente pode ser enviado depois
        // de um encaminhamento.
        $this->invalidateLiveSuggestions($state, 'handoff');

        $this->audit->log('conversation_response.handoff', 'Encaminhamento para atendimento humano.', $state, null, [
            'conversation_id' => $state->conversation_id,
            'reason' => $reason->value,
        ]);
    }

    /**
     * Invalida toda sugestao viva da conversa, liberando a unicidade.
     */
    public function invalidateLiveSuggestions(ConversationFlowState $state, string $reason): int
    {
        $suggestions = ConversationReplySuggestion::query()
            ->where('conversation_id', $state->conversation_id)
            ->whereNotNull('active_source_message_id')
            ->get();

        foreach ($suggestions as $suggestion) {
            $suggestion->forceFill([
                'status' => ReplySuggestionStatus::Superseded,
                'active_source_message_id' => null,
                'blocked_reason' => $reason,
            ])->save();
        }

        return $suggestions->count();
    }
}
