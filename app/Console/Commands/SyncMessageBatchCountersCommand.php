<?php

namespace App\Console\Commands;

use App\Models\MessageBatch;
use App\Services\MessageProcessing\BatchProgressService;
use Illuminate\Console\Command;

class SyncMessageBatchCountersCommand extends Command
{
    protected $signature = 'messages:sync-counters {batch?}';

    protected $description = 'Sincroniza contadores agregados de processamento dos lotes.';

    public function handle(BatchProgressService $progress): int
    {
        $query = MessageBatch::query();

        if ($this->argument('batch')) {
            $query->whereKey($this->argument('batch'));
        }

        $count = 0;
        $query->each(function (MessageBatch $batch) use ($progress, &$count): void {
            $progress->sync($batch);
            $count++;
        });

        $this->info("Lotes sincronizados: {$count}");

        return self::SUCCESS;
    }
}
