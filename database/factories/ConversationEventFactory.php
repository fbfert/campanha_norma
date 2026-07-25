<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ConversationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConversationEvent> */
class ConversationEventFactory extends Factory
{
    protected $model = ConversationEvent::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'event_type' => 'incoming_message_received',
            'description' => 'Evento de conversa.',
            'metadata' => [],
        ];
    }
}
