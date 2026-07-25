<?php

namespace App\Services\MessageProcessing;

use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Models\MessageProcessingEvent;
use App\Models\User;

class MessageProcessingEventService
{
    public function record(
        MessageBatch $batch,
        string $eventType,
        ?string $description = null,
        ?MessageBatchRecipient $recipient = null,
        ?User $user = null,
        ?string $errorCode = null,
        ?array $metadata = null
    ): MessageProcessingEvent {
        return MessageProcessingEvent::create([
            'message_batch_id' => $batch->id,
            'message_batch_recipient_id' => $recipient?->id,
            'user_id' => $user?->id,
            'event_type' => $eventType,
            'status' => $recipient?->processing_status?->value ?? $batch->status?->value,
            'description' => $description,
            'error_code' => $errorCode,
            'metadata' => $metadata,
        ]);
    }
}
