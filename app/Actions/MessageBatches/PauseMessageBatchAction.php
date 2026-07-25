<?php

namespace App\Actions\MessageBatches;

use App\Enums\MessageBatchStatus;
use App\Models\MessageBatch;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MessageProcessing\MessageProcessingEventService;
use RuntimeException;

class PauseMessageBatchAction
{
    public function __construct(private readonly MessageProcessingEventService $events, private readonly AuditLogger $audit) {}

    public function execute(MessageBatch $batch, User $user, ?string $reason = null): MessageBatch
    {
        if (! in_array($batch->status, [MessageBatchStatus::Queued, MessageBatchStatus::Processing], true)) {
            throw new RuntimeException('Somente lotes na fila ou em processamento podem ser pausados.');
        }

        $batch->forceFill([
            'status' => MessageBatchStatus::Paused,
            'pause_requested_at' => now(),
            'paused_at' => now(),
            'last_error_code' => $reason,
        ])->save();

        $this->events->record($batch, 'batch_paused', 'Processamento pausado.', user: $user, metadata: ['reason' => $reason]);
        $this->audit->log('message_batch.paused', 'Processamento pausado.', $batch, null, ['reason' => $reason], $user);

        return $batch->refresh();
    }
}
