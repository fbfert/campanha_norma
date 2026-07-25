<?php

namespace Database\Factories;

use App\Enums\MessageSendAttemptStatus;
use App\Models\MessageBatchRecipient;
use App\Models\MessageSendAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MessageSendAttempt> */
class MessageSendAttemptFactory extends Factory
{
    protected $model = MessageSendAttempt::class;

    public function definition(): array
    {
        return [
            'message_batch_recipient_id' => MessageBatchRecipient::factory(),
            'attempt_number' => 1,
            'request_id' => (string) Str::uuid(),
            'status' => MessageSendAttemptStatus::Started,
            'provider' => 'web',
            'started_at' => now(),
        ];
    }
}
