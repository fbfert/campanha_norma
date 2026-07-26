<?php

namespace Database\Factories;

use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Conversation> */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'connection_id' => 'principal',
            'provider' => 'web',
            'external_chat_id' => null,
            'status' => ConversationStatus::WaitingOperator,
            'priority' => ConversationPriority::Normal,
            'last_message_direction' => 'incoming',
            'last_message_at' => now(),
            'last_incoming_message_at' => now(),
            'last_synced_at' => null,
            'unread_count' => 1,
            'is_archived' => false,
        ];
    }
}
