<?php

namespace Database\Factories;

use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConversationMessage> */
class ConversationMessageFactory extends Factory
{
    protected $model = ConversationMessage::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'contact_id' => Contact::factory(),
            'direction' => ConversationMessageDirection::Incoming,
            'message_type' => 'text',
            'provider' => 'web',
            'external_message_id' => fake()->uuid(),
            'event_id' => fake()->uuid(),
            'sender_phone_snapshot' => '5549999999999',
            'recipient_phone_snapshot' => '5549888888888',
            'body' => 'Mensagem recebida de teste',
            'status' => ConversationMessageStatus::Received,
            'received_at' => now(),
        ];
    }
}
