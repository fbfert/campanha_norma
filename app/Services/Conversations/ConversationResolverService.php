<?php

namespace App\Services\Conversations;

use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Services\SystemSettingService;

class ConversationResolverService
{
    public function __construct(private readonly SystemSettingService $settings) {}

    public function resolve(?Contact $contact, ?string $connectionId, bool $incoming = true, ?string $senderPhone = null): Conversation
    {
        $query = Conversation::query()
            ->where('connection_id', $connectionId)
            ->where('is_archived', false)
            ->whereNotIn('status', [ConversationStatus::Closed->value]);

        if ($contact) {
            $query->where('contact_id', $contact->id);
        } else {
            $query->whereNull('contact_id');

            // Sem contato identificado, o telefone informado (bruto ou resolvido
            // via lid) e o único jeito de não misturar remetentes diferentes na
            // mesma conversa "sem contato".
            if (filled($senderPhone)) {
                $query->whereHas('messages', function ($messages) use ($senderPhone): void {
                    $messages->where('sender_phone_snapshot', $senderPhone)
                        ->orWhere('recipient_phone_snapshot', $senderPhone);
                });
            } else {
                $query->whereDoesntHave('messages');
            }
        }

        $conversation = $query->latest('last_message_at')->first();

        if (! $conversation) {
            return Conversation::create([
                'contact_id' => $contact?->id,
                'connection_id' => $connectionId,
                'status' => $incoming ? ConversationStatus::New : ConversationStatus::Open,
                'priority' => ConversationPriority::Normal,
                'last_message_direction' => $incoming ? ConversationMessageDirection::Incoming : ConversationMessageDirection::Outgoing,
                'last_message_at' => now(),
                'unread_count' => 0,
            ]);
        }

        if ($incoming) {
            $conversation->forceFill([
                'status' => ConversationStatus::tryFrom((string) $this->settings->get('inbox.default_status_after_incoming', 'waiting_operator')) ?? ConversationStatus::WaitingOperator,
                'is_archived' => false,
                'archived_at' => null,
                'archived_by' => null,
            ])->save();
        }

        return $conversation;
    }
}
