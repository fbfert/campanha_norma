<?php

namespace App\Services\ConversationAutomation;

use App\Enums\ConversationFlowStage;
use App\Models\ConversationFlowState;
use App\Models\ConversationFlowTransition;
use App\Models\ConversationMessage;
use App\Models\User;

/**
 * Único ponto autorizado a alterar `current_stage`. Toda transição gera histórico.
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

        // Ao entrar em espera, guarda de onde a conversa veio. Sem isso,
        // retomar so sabe voltar para o começo — e uma conversa que já tinha
        // autorização volta a pedir autorização, fazendo a próxima frase da
        // pessoa, que seria a opinião dela, ser lida como sim ou não.
        if ($this->isHold($to) && ! $this->isHold($from)) {
            $state->forceFill(['stage_before_hold' => $from?->value]);
        }

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

    /**
     * Estágio para onde a conversa deve voltar ao ser retomada.
     *
     * Sem registro anterior, o pedido de permissão continua sendo o destino
     * seguro: e o único estágio que não presume nada sobre o que já foi dito.
     * Estágio terminal também não serve — retomar para encerrado encerraria a
     * conversa no mesmo instante.
     */
    public function stageToResume(ConversationFlowState $state): ConversationFlowStage
    {
        $anterior = $state->stage_before_hold
            ? ConversationFlowStage::tryFrom($state->stage_before_hold)
            : null;

        if ($anterior === null || $anterior->isTerminal() || $this->isHold($anterior)) {
            return ConversationFlowStage::WaitingPermission;
        }

        return $anterior;
    }

    /** A conversa esta em espera, aguardando gente? */
    private function isHold(?ConversationFlowStage $stage): bool
    {
        return in_array($stage, [ConversationFlowStage::WaitingHuman, ConversationFlowStage::Paused], true);
    }
}
