<?php

namespace App\Observers;

use App\Enums\UserStatus;
use App\Models\Conversation;
use App\Models\User;
use App\Services\SystemSettingService;

/**
 * Atribuição padrão de conversa.
 *
 * Toda conversa nova nasce atribuída ao responsável configurado em
 * `conversations.default_assignee_id`. Sem a chave preenchida, nada acontece —
 * a conversa continua sem responsável, como antes.
 *
 * A atribuição automática interage com o autoenvio de respostas geradas:
 * `SuggestionSendGuard` recusa envio automático em conversa atribuída, salvo
 * quando `ai.response.auto_send_when_assigned` estiver ligado. Ligar a
 * atribuição padrão sem ligar essa chave desliga o autoenvio na prática.
 */
class ConversationObserver
{
    public function creating(Conversation $conversation): void
    {
        if ($conversation->assigned_user_id !== null) {
            return;
        }

        $conversation->assigned_user_id = $this->defaultAssigneeId();
    }

    public function created(Conversation $conversation): void
    {
        if ($conversation->assigned_user_id === null) {
            return;
        }

        // Histórico de atribuição, igual ao que a tela grava. Sem `assigned_by`:
        // não houve pessoa decidindo, foi a regra padrão.
        $conversation->assignments()->create([
            'assigned_user_id' => $conversation->assigned_user_id,
            'assigned_by' => null,
            'assigned_at' => now(),
            'reason' => 'Atribuição padrão do sistema.',
        ]);
    }

    /**
     * Responsável padrão, se houver um configurado e ainda ativo.
     *
     * Usuário inativo ou removido devolve nulo: atribuir conversa a quem não
     * entra mais no sistema esconde a conversa de todo mundo.
     */
    private function defaultAssigneeId(): ?int
    {
        $id = (int) app(SystemSettingService::class)->get('conversations.default_assignee_id', 0);

        if ($id < 1) {
            return null;
        }

        $ativo = User::query()
            ->whereKey($id)
            ->where('status', UserStatus::Active)
            ->exists();

        return $ativo ? $id : null;
    }
}
