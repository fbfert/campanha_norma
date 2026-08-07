<?php

namespace App\Services\MessageProcessing;

use App\Enums\MessageBatchStatus;
use App\Enums\MessageRecipientProcessingStatus;
use App\Jobs\SendMessageRecipientJob;
use App\Models\MessageBatch;
use Illuminate\Support\Facades\Cache;

class BatchDispatcherService
{
    public function __construct(private readonly BatchProgressService $progress, private readonly MessageProcessingEventService $events) {}

    public function dispatch(MessageBatch $batch, int $processingVersion): void
    {
        if ($batch->processing_version !== $processingVersion || ! in_array($batch->status, [MessageBatchStatus::Queued, MessageBatchStatus::Processing], true)) {
            return;
        }

        Cache::lock("message-batch:{$batch->id}", 30)->block(1, function () use ($batch, $processingVersion): void {
            $batch->refresh();

            if ($batch->processing_version !== $processingVersion || ! in_array($batch->status, [MessageBatchStatus::Queued, MessageBatchStatus::Processing], true)) {
                return;
            }

            $recipient = $batch->recipients()
                ->whereIn('processing_status', [
                    MessageRecipientProcessingStatus::Pending->value,
                    MessageRecipientProcessingStatus::WaitingSchedule->value,
                    MessageRecipientProcessingStatus::WaitingMinuteLimit->value,
                    MessageRecipientProcessingStatus::WaitingMinimumInterval->value,
                    MessageRecipientProcessingStatus::WaitingReciprocity->value,
                    MessageRecipientProcessingStatus::WaitingHourLimit->value,
                    MessageRecipientProcessingStatus::WaitingDayLimit->value,
                    MessageRecipientProcessingStatus::RetryWait->value,
                    MessageRecipientProcessingStatus::Queued->value,
                    MessageRecipientProcessingStatus::FailedTemporary->value,
                ])
                ->where(function ($query): void {
                    $query->whereNull('retry_at')->orWhere('retry_at', '<=', now());
                })
                ->orderByRaw('random_position is null, random_position asc')
                ->first();

            if (! $recipient) {
                $this->progress->completeIfFinished($batch);

                return;
            }

            $batch->forceFill([
                'status' => MessageBatchStatus::Processing,
                'processing_started_at' => $batch->processing_started_at ?? now(),
                'last_dispatch_at' => now(),
            ])->save();

            $recipient->forceFill([
                'processing_status' => MessageRecipientProcessingStatus::Queued,
                'queued_at' => now(),
                'processing_version' => $processingVersion,
            ])->save();

            $this->events->record($batch, 'recipient_queued', 'Destinatário liberado para envio.', $recipient);
            $this->progress->sync($batch);

            SendMessageRecipientJob::dispatch($recipient->id, $processingVersion)->onQueue('whatsapp-messages');
        });
    }
}
