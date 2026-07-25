<?php

namespace Database\Factories;

use App\Models\MessageBatch;
use App\Models\MessageBatchEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MessageBatchEvent> */
class MessageBatchEventFactory extends Factory
{
    protected $model = MessageBatchEvent::class;

    public function definition(): array
    {
        return [
            'message_batch_id' => MessageBatch::factory(),
            'user_id' => User::factory(),
            'event_type' => 'created',
            'description' => 'Evento de teste.',
            'metadata' => [],
        ];
    }
}
