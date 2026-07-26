<?php

namespace App\Jobs;

use App\Enums\ConversationSyncStatus;
use App\Models\ConversationSyncRun;
use App\Services\Conversations\ConversationSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class SyncWhatsAppConversationsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $syncRunId)
    {
        $this->onQueue('whatsapp-conversation-sync');
    }

    public function handle(ConversationSyncService $sync): void
    {
        $run = ConversationSyncRun::find($this->syncRunId);
        if (! $run || ! in_array($run->status, [ConversationSyncStatus::Pending, ConversationSyncStatus::Running], true)) {
            return;
        }

        $lock = Cache::lock('conversations:sync:active', 600);
        if (! $lock->get()) {
            return;
        }

        try {
            $sync->run($run, $run->options ?? []);
        } finally {
            $lock->release();
        }
    }
}
