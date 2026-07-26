<?php

namespace App\Console\Commands;

use App\Enums\ConversationMessageDirection;
use App\Models\Conversation;
use Illuminate\Console\Command;

class RebuildConversationUnreadCommand extends Command
{
    protected $signature = 'conversations:rebuild-unread';

    protected $description = 'Recalcula contadores internos de mensagens nao lidas das conversas.';

    public function handle(): int
    {
        $count = 0;

        Conversation::query()->chunkById(100, function ($conversations) use (&$count): void {
            foreach ($conversations as $conversation) {
                $conversation->forceFill([
                    'unread_count' => $conversation->messages()
                        ->where('direction', ConversationMessageDirection::Incoming)
                        ->whereNull('read_at')
                        ->count(),
                ])->save();
                $count++;
            }
        });

        $this->info("Conversas recalculadas: {$count}");

        return self::SUCCESS;
    }
}
