<?php

namespace App\Services\ConversationAutomation;

use App\Enums\ContactStatus;
use App\Models\ConversationFlowState;
use App\Services\SystemSettingService;
use Illuminate\Support\Carbon;

/**
 * Porta única de verificação antes de qualquer ação automática.
 * Retorna sempre um motivo legível para auditoria e para a tela de estado.
 */
class ConversationAutomationGuard
{
    public function __construct(private readonly SystemSettingService $settings) {}

    public function automationEnabled(): bool
    {
        return (bool) $this->settings->get('conversation_automation.enabled', '0');
    }

    public function autoSendEnabled(): bool
    {
        return (bool) $this->settings->get('conversation_automation.auto_send_enabled', '0');
    }

    /**
     * Pode avaliar a conversa? Não decide envio, apenas processamento do fluxo.
     *
     * @return array{allowed: bool, reason: ?string}
     */
    public function canEvaluate(ConversationFlowState $state): array
    {
        if (! $this->automationEnabled()) {
            return $this->deny('automacao_desabilitada');
        }

        if ($state->is_paused) {
            return $this->deny('conversa_pausada');
        }

        if ($state->current_stage->isTerminal()) {
            return $this->deny('fluxo_encerrado');
        }

        $flow = $state->flow;
        if (! $flow || ! $flow->isRunnable()) {
            return $this->deny('fluxo_inativo');
        }

        if ($state->isExpired()) {
            return $this->deny('fluxo_expirado');
        }

        return $this->allow();
    }

    /**
     * Pode criar e enfileirar uma mensagem automática?
     *
     * @return array{allowed: bool, reason: ?string}
     */
    public function canSend(ConversationFlowState $state): array
    {
        $evaluate = $this->canEvaluate($state);
        if (! $evaluate['allowed']) {
            return $evaluate;
        }

        if (! $this->autoSendEnabled()) {
            return $this->deny('envio_automatico_desabilitado');
        }

        $contact = $state->conversation?->contact;
        if (! $contact) {
            return $this->deny('contato_nao_identificado');
        }

        if ($contact->do_not_contact) {
            return $this->deny('contato_nao_contatar');
        }

        if ($contact->status !== ContactStatus::Active) {
            return $this->deny('contato_inativo');
        }

        if (blank($contact->phone_normalized)) {
            return $this->deny('contato_sem_telefone');
        }

        $max = (int) $this->settings->get('conversation_automation.max_automated_messages', 3);
        if ($state->automated_messages_count >= $max) {
            return $this->deny('limite_de_mensagens_automaticas');
        }

        if (! $this->withinWindow()) {
            return $this->deny('fora_da_janela_de_horario');
        }

        return $this->allow();
    }

    /**
     * Janela de horário permitida para envio automático.
     */
    public function withinWindow(?Carbon $now = null): bool
    {
        $start = (string) $this->settings->get('conversation_automation.window_start', '08:00');
        $end = (string) $this->settings->get('conversation_automation.window_end', '20:00');

        if ($start === '' || $end === '' || $start === $end) {
            return true;
        }

        $now ??= now();
        $current = $now->format('H:i');

        // Janela que não cruza a meia-noite.
        if ($start < $end) {
            return $current >= $start && $current <= $end;
        }

        // Janela que cruza a meia-noite (ex.: 20:00 as 08:00).
        return $current >= $start || $current <= $end;
    }

    /** @return array{allowed: bool, reason: ?string} */
    private function allow(): array
    {
        return ['allowed' => true, 'reason' => null];
    }

    /** @return array{allowed: bool, reason: ?string} */
    private function deny(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason];
    }
}
