<?php

namespace App\Console\Commands;

use App\Enums\ConversationSyncStatus;
use App\Models\ConversationSyncRun;
use Illuminate\Console\Command;

class RecoverConversationSyncCommand extends Command
{
    protected $signature = 'conversations:recover-sync {--minutes=30}';

    protected $description = 'Marca sincronizacoes de conversas presas como falhas tecnicas.';

    public function handle(): int
    {
        $minutes = max(5, (int) $this->option('minutes'));
        $updated = ConversationSyncRun::query()
            ->where('status', ConversationSyncStatus::Running->value)
            ->where('last_heartbeat_at', '<', now()->subMinutes($minutes))
            ->update([
                'status' => ConversationSyncStatus::Failed,
                'finished_at' => now(),
                'error_code' => 'SYNC_STUCK',
                'error_message' => 'Sincronizacao marcada como presa pelo comando de recuperacao.',
                'updated_at' => now(),
            ]);

        $this->info("Sincronizacoes recuperadas: {$updated}");

        return self::SUCCESS;
    }
}
