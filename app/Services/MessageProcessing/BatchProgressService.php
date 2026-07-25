<?php

namespace App\Services\MessageProcessing;

use App\Enums\MessageBatchStatus;
use App\Enums\MessageRecipientProcessingStatus;
use App\Models\MessageBatch;

class BatchProgressService
{
    public function sync(MessageBatch $batch): MessageBatch
    {
        $counts = $batch->recipients()
            ->selectRaw('processing_status, count(*) as total')
            ->groupBy('processing_status')
            ->pluck('total', 'processing_status');

        $batch->forceFill([
            'total_pending' => (int) $counts->get(MessageRecipientProcessingStatus::Pending->value, 0),
            'total_queued' => (int) $counts->get(MessageRecipientProcessingStatus::Queued->value, 0),
            'total_processing' => (int) $counts->get(MessageRecipientProcessingStatus::Processing->value, 0),
            'total_sent' => (int) $counts->get(MessageRecipientProcessingStatus::Sent->value, 0),
            'total_cancelled' => (int) $counts->get(MessageRecipientProcessingStatus::Cancelled->value, 0),
            'total_retrying' => (int) $counts->get(MessageRecipientProcessingStatus::RetryWait->value, 0),
            'total_failed' => (int) $counts->get(MessageRecipientProcessingStatus::FailedPermanent->value, 0)
                + (int) $counts->get(MessageRecipientProcessingStatus::FailedTemporary->value, 0),
        ])->save();

        return $batch->refresh();
    }

    public function completeIfFinished(MessageBatch $batch): MessageBatch
    {
        $batch = $this->sync($batch);
        $active = $batch->recipients()
            ->whereIn('processing_status', collect(MessageRecipientProcessingStatus::cases())
                ->filter(fn (MessageRecipientProcessingStatus $status): bool => $status->isActive())
                ->map->value
                ->all())
            ->exists();

        if (! $active && in_array($batch->status, [MessageBatchStatus::Queued, MessageBatchStatus::Processing], true)) {
            $batch->forceFill([
                'status' => $batch->total_failed > 0 ? MessageBatchStatus::CompletedWithErrors : MessageBatchStatus::Completed,
                'completed_at' => now(),
            ])->save();
        }

        return $batch->refresh();
    }
}
