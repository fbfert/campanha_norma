<?php

namespace App\Services\Conversations;

use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageStatus;
use App\Jobs\SendManualConversationReplyJob;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SystemSettingService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManualReplyService
{
    public function __construct(private readonly SystemSettingService $settings, private readonly AuditLogger $audit, private readonly ConversationEventService $events) {}

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
            throw ValidationException::withMessages(['conversation' => 'Este contato nao pode receber resposta pelo sistema.']);
        }

        if (! (bool) $this->settings->get('inbox.allow_unassigned_reply', false) && $conversation->assigned_user_id !== $user->id) {
            throw ValidationException::withMessages(['conversation' => 'Assuma a conversa antes de responder.']);
        }

        $message = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'direction' => ConversationMessageDirection::Outgoing,
            'message_type' => 'text',
            'provider' => config('whatsapp.provider', 'web'),
            'request_id' => (string) Str::uuid(),
            'sender_phone_snapshot' => null,
            'recipient_phone_snapshot' => $conversation->contact->phone_normalized,
            'sender_name_snapshot' => $user->name,
            'body' => $body,
            'status' => ConversationMessageStatus::Pending,
            'created_by' => $user->id,
        ]);

        $this->events->record($conversation, 'reply_requested', 'Resposta manual solicitada.', $message, $user);
        $this->audit->log('conversation.manual_reply_requested', 'Resposta manual solicitada.', $message, null, ['conversation_id' => $conversation->id], $user);

        SendManualConversationReplyJob::dispatch($message->id)->onQueue('whatsapp-manual-replies');

        return $message;
    }
}
