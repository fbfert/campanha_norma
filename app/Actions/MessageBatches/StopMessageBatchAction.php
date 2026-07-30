<?php

namespace App\Actions\MessageBatches;

use App\Enums\MessageBatchStatus;
use App\Enums\MessageRecipientProcessingStatus;
use App\Models\MessageBatch;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MessageProcessing\BatchProgressService;
use App\Services\MessageProcessing\MessageProcessingEventService;
use RuntimeException;

class StopMessageBatchAction
{
    public function __construct(
        private readonly BatchProgressService $progress,
        private readonly MessageProcessingEventService $events,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(MessageBatch $batch, User $user, string $reason): MessageBatch
    {
        if (! in_array($batch->status, [MessageBatchStatus::Queued, MessageBatchStatus::Processing, MessageBatchStatus::Paused, MessageBatchStatus::Pausing], true)) {
            throw new RuntimeException('Este lote não pode ser parado no estado atual.');
        }

        $cancelable = [
            MessageRecipientProcessingStatus::Pending->value,
            MessageRecipientProcessingStatus::WaitingSchedule->value,
            MessageRecipientProcessingStatus::WaitingMinuteLimit->value,
            MessageRecipientProcessingStatus::WaitingHourLimit->value,
            MessageRecipientProcessingStatus::WaitingDayLimit->value,
            MessageRecipientProcessingStatus::Queued->value,
            MessageRecipientProcessingStatus::RetryWait->value,
            MessageRecipientProcessingStatus::FailedTemporary->value,
        ];

        $batch->forceFill(['status' => MessageBatchStatus::Stopping, 'stop_requested_at' => now(), 'cancel_reason' => $reason])->save();
        $batch->recipients()->whereIn('processing_status', $cancelable)->update([
            'processing_status' => MessageRecipientProcessingStatus::Cancelled->value,
            'cancelled_at' => now(),
            'error_code' => 'BATCH_STOPPED',
            'error_message' => 'Lote parado pelo usuário.',
        ]);

        $batch->forceFill(['status' => MessageBatchStatus::Stopped, 'stopped_at' => now(), 'cancelled_by' => $user->id])->save();

        $this->events->record($batch, 'batch_stopped', 'Processamento parado definitivamente.', user: $user, metadata: ['reason' => $reason]);
        $this->audit->log('message_batch.stopped', 'Processamento parado definitivamente.', $batch, null, ['reason' => $reason], $user);

        return $this->progress->sync($batch);
    }
}
