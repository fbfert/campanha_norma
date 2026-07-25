<?php

namespace App\Services\Conversations;

use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Models\User;

class ConversationEventService
{
    public function record(Conversation $conversation, string $type, ?string $description = null, ?ConversationMessage $message = null, ?User $user = null, ?array $metadata = null): ConversationEvent
    {
        return ConversationEvent::create([
            'conversation_id' => $conversation->id,
            'conversation_message_id' => $message?->id,
            'user_id' => $user?->id,
            'event_type' => $type,
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }
}
