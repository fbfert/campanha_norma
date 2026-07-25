<?php

namespace App\Console\Commands;

use App\Models\MessageBatch;
use App\Services\MessageProcessing\BatchProgressService;
use Illuminate\Console\Command;

class RecalculateMessageBatchCommand extends Command
{
    protected $signature = 'messages:recalculate-batch {batch}';

    protected $description = 'Recalcula um lote especifico.';

    public function handle(BatchProgressService $progress): int
    {
        $batch = MessageBatch::query()->findOrFail($this->argument('batch'));
        $progress->sync($batch);
        $this->info("Lote {$batch->id} recalculado.");

        return self::SUCCESS;
    }
}
