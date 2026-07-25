<?php

namespace App\Console\Commands;

use App\Enums\MessageBatchStatus;
use App\Jobs\DispatchMessageBatchJob;
use App\Models\MessageBatch;
use App\Services\AuditLogger;
use App\Services\SystemSettingService;
use Illuminate\Console\Command;

class DispatchPendingMessagesCommand extends Command
{
    protected $signature = 'messages:dispatch-pending';

    protected $description = 'Despacha lotes de mensagens pendentes para a fila controlada.';

    public function handle(AuditLogger $audit, SystemSettingService $settings): int
    {
        $count = 0;

        MessageBatch::query()
            ->whereIn('status', [MessageBatchStatus::Queued, MessageBatchStatus::Processing])
            ->where(fn ($query) => $query->whereNull('next_dispatch_at')->orWhere('next_dispatch_at', '<=', now()))
            ->orderBy('next_dispatch_at')
            ->limit((int) $settings->get('messages.dispatch_batch_size', 1))
            ->get()
            ->each(function (MessageBatch $batch) use (&$count): void {
                DispatchMessageBatchJob::dispatch($batch->id, $batch->processing_version)->onQueue('whatsapp-messages');
                $count++;
            });

        $audit->log('message_processing.maintenance_executed', 'Comando de despacho de mensagens executado.', null, null, ['dispatched_batches' => $count]);
        $this->info("Lotes despachados: {$count}");

        return self::SUCCESS;
    }
}
