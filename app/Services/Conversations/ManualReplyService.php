<?php

namespace App\Services\Conversations;

use App\Enums\ConversationMessageOrigin;
use App\Jobs\SendManualConversationReplyJob;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\SystemSettingService;
use Illuminate\Validation\ValidationException;

class ManualReplyService
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly ConversationReplyService $replies,
    ) {}

    public function request(Conversation $conversation, User $user, string $body): ConversationMessage
    {
        $body = trim($body);
        $max = (int) $this->settings->get('inbox.maximum_manual_reply_length', 4096);

        if ($body === '' || mb_strlen($body) > $max) {
            throw ValidationException::withMessages(['body' => 'Informe uma resposta valida dentro do limite permitido.']);
        }

        if (! $conversation->contact) {
            throw ValidationException::withMessages(['conversation' => 'Associe um contato antes de responder.']);
        }

        if ($conversation->contact->do_not_contact || $conversation->contact->status->value !== 'active') {
            throw ValidationException::withMessages(['conversation' => 'Este contato não pode receber resposta pelo sistema.']);
        }

        if (! (bool) $this->settings->get('inbox.allow_unassigned_reply', false) && $conversation->assigned_user_id !== $user->id) {
            throw ValidationException::withMessages(['conversation' => 'Assuma a conversa antes de responder.']);
        }

        // As validações acima são específicas da resposta manual e continuam
        // aqui. A criação da mensagem passa pelo serviço de saída compartilhado,
        // com exatamente os mesmos campos, evento, auditoria e fila de antes.
        $message = $this->replies->createPending(
            conversation: $conversation,
            body: $body,
            origin: ConversationMessageOrigin::Manual,
            user: $user,
            eventType: 'reply_requested',
            eventDescription: 'Resposta manual solicitada.',
            auditAction: 'conversation.manual_reply_requested',
            auditDescription: 'Resposta manual solicitada.',
        );

        SendManualConversationReplyJob::dispatch($message->id)->onQueue('whatsapp-manual-replies');

        return $message;
    }
}
