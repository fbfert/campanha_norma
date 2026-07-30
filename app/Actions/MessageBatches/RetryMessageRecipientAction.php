<?php

namespace App\Actions\MessageBatches;

use App\Enums\MessageRecipientProcessingStatus;
use App\Jobs\DispatchMessageBatchJob;
use App\Models\MessageBatchRecipient;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MessageProcessing\MessageProcessingEventService;
use RuntimeException;

class RetryMessageRecipientAction
{
    public function __construct(private readonly MessageProcessingEventService $events, private readonly AuditLogger $audit) {}

    public function execute(MessageBatchRecipient $recipient, User $user): MessageBatchRecipient
    {
        if ($recipient->processing_status !== MessageRecipientProcessingStatus::FailedTemporary) {
            throw new RuntimeException('Somente falhas temporárias podem ser tentadas novamente.');
        }

        $recipient->forceFill([
            'processing_status' => MessageRecipientProcessingStatus::Pending,
            'retry_at' => now(),
            'error_code' => null,
            'error_message' => null,
        ])->save();

        $batch = $recipient->batch;
        $this->events->record($batch, 'recipient_retry_scheduled', 'Nova tentativa solicitada.', $recipient, $user);
        $this->audit->log('message_recipient.retry_requested', 'Nova tentativa solicitada.', $recipient, null, ['batch_id' => $batch->id], $user);
        DispatchMessageBatchJob::dispatch($batch->id, $batch->processing_version)->onQueue('whatsapp-messages');

        return $recipient->refresh();
    }
}
