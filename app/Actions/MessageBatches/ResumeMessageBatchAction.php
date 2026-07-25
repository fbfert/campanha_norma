<?php

namespace App\Actions\MessageBatches;

use App\Enums\MessageBatchStatus;
use App\Jobs\DispatchMessageBatchJob;
use App\Models\MessageBatch;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MessageProcessing\MessageProcessingEventService;
use RuntimeException;

class ResumeMessageBatchAction
{
    public function __construct(private readonly MessageProcessingEventService $events, private readonly AuditLogger $audit) {}

    public function execute(MessageBatch $batch, User $user): MessageBatch
    {
        if ($batch->status !== MessageBatchStatus::Paused) {
            throw new RuntimeException('Somente lotes pausados podem ser retomados.');
        }

        $batch->forceFill([
            'status' => MessageBatchStatus::Queued,
            'resume_requested_at' => now(),
            'next_dispatch_at' => now(),
            'processing_version' => $batch->processing_version + 1,
        ])->save();

        $this->events->record($batch, 'batch_resumed', 'Processamento retomado.', user: $user);
        $this->audit->log('message_batch.resumed', 'Processamento retomado.', $batch, null, ['status' => 'queued'], $user);

        DispatchMessageBatchJob::dispatch($batch->id, $batch->processing_version)->onQueue('whatsapp-messages');

        return $batch->refresh();
    }
}
