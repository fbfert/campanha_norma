<?php

namespace Database\Factories;

use App\Models\MessageBatch;
use App\Models\MessageProcessingEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MessageProcessingEvent> */
class MessageProcessingEventFactory extends Factory
{
    protected $model = MessageProcessingEvent::class;

    public function definition(): array
    {
        return [
            'message_batch_id' => MessageBatch::factory(),
            'event_type' => 'batch_started',
            'status' => 'queued',
            'description' => 'Evento de processamento.',
            'metadata' => [],
        ];
    }
}
