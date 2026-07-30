<?php

namespace App\Actions\MessageBatches;

use App\Enums\MessageBatchRecipientEligibility;
use App\Enums\MessageBatchStatus;
use App\Enums\MessageRecipientProcessingStatus;
use App\Jobs\DispatchMessageBatchJob;
use App\Models\MessageBatch;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MessageProcessing\BatchProgressService;
use App\Services\MessageProcessing\MessageProcessingEventService;
use App\Services\MessageProcessing\SendingSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class StartMessageBatchAction
{
    public function __construct(
        private readonly SendingSettingsService $settings,
        private readonly BatchProgressService $progress,
        private readonly MessageProcessingEventService $events,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(MessageBatch $batch, User $user): MessageBatch
    {
        if ($batch->status !== MessageBatchStatus::Ready) {
            throw new RuntimeException('Somente lotes preparados podem ser iniciados.');
        }

        $settings = $this->settings->current();
        $eligibleCount = $batch->recipients()->where('eligibility_status', MessageBatchRecipientEligibility::Eligible)->count();

        if ($eligibleCount === 0) {
            throw new RuntimeException('O lote não possui destinatários aptos.');
        }

        $batch = DB::transaction(function () use ($batch, $user, $settings): MessageBatch {
            $locked = MessageBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== MessageBatchStatus::Ready) {
                throw new RuntimeException('Este lote já foi iniciado ou alterado.');
            }

            $version = $locked->processing_version + 1;

            $locked->recipients()
                ->where('eligibility_status', MessageBatchRecipientEligibility::Eligible)
                ->update([
                    'processing_status' => MessageRecipientProcessingStatus::Pending->value,
                    'max_attempts' => $settings->max_attempts,
                    'processing_version' => $version,
                    'queued_at' => now(),
                ]);

            $locked->recipients()
                ->where('eligibility_status', MessageBatchRecipientEligibility::Eligible)
                ->whereNull('request_id')
                ->each(fn ($recipient) => $recipient->forceFill(['request_id' => (string) Str::uuid()])->save());

            $locked->forceFill([
                'status' => MessageBatchStatus::Queued,
                'queued_at' => now(),
                'next_dispatch_at' => now(),
                'processing_version' => $version,
                'updated_by' => $user->id,
            ])->save();

            $this->events->record($locked, 'batch_started', 'Lote iniciado para processamento.', user: $user);
            $this->audit->log('message_batch.started', 'Lote iniciado para processamento.', $locked, null, ['status' => 'queued'], $user);

            return $locked;
        });

        $this->progress->sync($batch);
        DispatchMessageBatchJob::dispatch($batch->id, $batch->processing_version)->onQueue('whatsapp-messages');

        return $batch->refresh();
    }
}
