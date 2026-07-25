<?php

namespace App\Jobs;

use App\Models\MessageBatch;
use App\Services\MessageProcessing\BatchDispatcherService;
use App\Services\Monitoring\HeartbeatService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchMessageBatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $batchId, public readonly int $processingVersion)
    {
        $this->onQueue('whatsapp-messages');
    }

    public function handle(BatchDispatcherService $dispatcher, HeartbeatService $heartbeat): void
    {
        $heartbeat->worker('whatsapp-messages', self::class);
        $batch = MessageBatch::query()->find($this->batchId);

        if ($batch) {
            $dispatcher->dispatch($batch, $this->processingVersion);
        }
    }
}
