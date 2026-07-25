<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use Illuminate\Console\Command;

class InboxRebuildConversationStatusCommand extends Command
{
    protected $signature = 'inbox:rebuild-conversation-status';

    protected $description = 'Recalcula campos derivados simples de conversas.';

    public function handle(): int
    {
        $count = 0;
        Conversation::query()->each(function (Conversation $conversation) use (&$count): void {
            $last = $conversation->messages()->latest('created_at')->first();
            if ($last) {
                $conversation->update([
                    'last_message_direction' => $last->direction,
                    'last_message_at' => $last->created_at,
                ]);
                $count++;
            }
        });

        $this->info("Conversas recalculadas: {$count}.");

        return self::SUCCESS;
    }
}
