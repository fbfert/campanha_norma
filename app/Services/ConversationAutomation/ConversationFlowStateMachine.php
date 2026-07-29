<?php

namespace App\Services\ConversationAutomation;

use App\Enums\ConversationFlowStage;
use App\Models\ConversationFlowState;
use App\Models\ConversationFlowTransition;
use App\Models\ConversationMessage;
use App\Models\User;

/**
 * Unico ponto autorizado a alterar `current_stage`. Toda transicao gera historico.
 */
class ConversationFlowStateMachine
{
    public function transition(
        ConversationFlowState $state,
        ConversationFlowStage $to,
        string $triggerEvent,
        ?ConversationMessage $message = null,
        ?string $decision = null,
        ?User $user = null,
        ?array $metadata = null,
    ): ConversationFlowState {
        $from = $state->current_stage;

        $state->forceFill([
            'current_stage' => $to,
            'last_transition_at' => now(),
        ]);

        if ($to === ConversationFlowStage::Completed || $to->isTerminal()) {
            $state->forceFill(['completed_at' => $state->completed_at ?? now()]);
        }

        $state->save();

        ConversationFlowTransition::create([
            'conversation_flow_state_id' => $state->id,
            'conversation_id' => $state->conversation_id,
            'from_stage' => $from?->value,
            'to_stage' => $to->value,
            'trigger_event' => $triggerEvent,
            'conversation_message_id' => $message?->id,
            'decision' => $decision,
            'user_id' => $user?->id,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);

        return $state;
    }

    /**
     * Encerra o fluxo registrando o motivo, sem permitir sobrescrever um encerramento anterior.
     */
    public function finish(
        ConversationFlowState $state,
        ConversationFlowStage $to,
        string $endReason,
        string $triggerEvent,
        ?ConversationMessage $message = null,
        ?string $decision = null,
        ?User $user = null,
        ?array $metadata = null,
    ): ConversationFlowState {
        $state->forceFill(['end_reason' => $state->end_reason ?? $endReason])->save();

        return $this->transition($state, $to, $triggerEvent, $message, $decision, $user, $metadata);
    }

    public function markForHuman(ConversationFlowState $state, string $triggerEvent, ?ConversationMessage $message = null, ?string $decision = null, ?User $user = null): ConversationFlowState
    {
        $state->forceFill(['needs_human_review' => true])->save();

        return $this->transition($state, ConversationFlowStage::WaitingHuman, $triggerEvent, $message, $decision, $user);
    }
}
