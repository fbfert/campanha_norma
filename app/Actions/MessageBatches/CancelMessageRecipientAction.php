<?php

namespace App\Actions\MessageBatches;

use App\Enums\MessageRecipientProcessingStatus;
use App\Models\MessageBatchRecipient;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MessageProcessing\BatchProgressService;
use App\Services\MessageProcessing\MessageProcessingEventService;
use RuntimeException;

class CancelMessageRecipientAction
{
    public function __construct(
        private readonly BatchProgressService $progress,
        private readonly MessageProcessingEventService $events,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(MessageBatchRecipient $recipient, User $user): MessageBatchRecipient
    {
        $cancelable = [
            MessageRecipientProcessingStatus::Pending,
            MessageRecipientProcessingStatus::WaitingSchedule,
            MessageRecipientProcessingStatus::WaitingMinuteLimit,
        MessageRecipientProcessingStatus::WaitingMinimumInterval,
        MessageRecipientProcessingStatus::WaitingReciprocity,
            MessageRecipientProcessingStatus::WaitingHourLimit,
            MessageRecipientProcessingStatus::WaitingDayLimit,
            MessageRecipientProcessingStatus::Queued,
            MessageRecipientProcessingStatus::RetryWait,
        ];

        if (! in_array($recipient->processing_status, $cancelable, true)) {
            throw new RuntimeException('Este destinatário não pode ser cancelado no estado atual.');
        }

        $recipient->forceFill([
            'processing_status' => MessageRecipientProcessingStatus::Cancelled,
            'cancelled_at' => now(),
            'error_code' => 'RECIPIENT_CANCELLED',
            'error_message' => 'Destinatário cancelado manualmente.',
        ])->save();

        $batch = $recipient->batch;
        $this->events->record($batch, 'recipient_cancelled', 'Destinatário cancelado.', $recipient, $user);
        $this->audit->log('message_recipient.cancelled', 'Destinatário cancelado.', $recipient, null, ['batch_id' => $batch->id], $user);
        $this->progress->sync($batch);

        return $recipient->refresh();
    }
}
