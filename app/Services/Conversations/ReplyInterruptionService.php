<?php

namespace App\Services\Conversations;

use App\Enums\MessageRecipientProcessingStatus;
use App\Models\Contact;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Services\MessageProcessing\BatchProgressService;

class ReplyInterruptionService
{
    public function __construct(private readonly BatchProgressService $progress) {}

    public function interrupt(?Contact $contact, ?string $phone): int
    {
        $statuses = [
            MessageRecipientProcessingStatus::Eligible->value,
            MessageRecipientProcessingStatus::Pending->value,
            MessageRecipientProcessingStatus::WaitingSchedule->value,
            MessageRecipientProcessingStatus::WaitingMinuteLimit->value,
            MessageRecipientProcessingStatus::WaitingHourLimit->value,
            MessageRecipientProcessingStatus::WaitingDayLimit->value,
            MessageRecipientProcessingStatus::Queued->value,
            MessageRecipientProcessingStatus::RetryWait->value,
            MessageRecipientProcessingStatus::FailedTemporary->value,
        ];

        $query = MessageBatchRecipient::query()
            ->whereIn('processing_status', $statuses)
            ->where(function ($query) use ($contact, $phone): void {
                if ($contact) {
                    $query->orWhere('contact_id', $contact->id);
                }
                if ($phone) {
                    $query->orWhere('contact_phone_snapshot', $phone);
                }
            });

        $batchIds = (clone $query)->pluck('message_batch_id')->unique();
        $count = $query->update([
            'processing_status' => MessageRecipientProcessingStatus::Skipped,
            'error_code' => 'CONTACT_REPLIED',
            'error_message' => 'O contato respondeu e saiu dos envios automáticos pendentes.',
            'failed_at' => now(),
            'updated_at' => now(),
        ]);

        MessageBatch::query()->whereIn('id', $batchIds)->get()->each(fn (MessageBatch $batch) => $this->progress->sync($batch));

        return $count;
    }
}
