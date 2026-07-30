<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use Illuminate\Console\Command;

class InboxSyncUnreadCountsCommand extends Command
{
    protected $signature = 'inbox:sync-unread-counts';

    protected $description = 'Recalcula contadores de mensagens não lidas das conversas.';

    public function handle(): int
    {
        $count = 0;
        Conversation::query()->each(function (Conversation $conversation) use (&$count): void {
            $conversation->update([
                'unread_count' => $conversation->messages()->where('direction', 'incoming')->whereNull('read_at')->count(),
            ]);
            $count++;
        });

        $this->info("Contadores sincronizados em {$count} conversa(s).");

        return self::SUCCESS;
    }
}
