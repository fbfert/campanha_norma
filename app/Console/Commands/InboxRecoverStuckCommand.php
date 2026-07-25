<?php

namespace App\Console\Commands;

use App\Enums\ConversationMessageStatus;
use App\Models\ConversationMessage;
use Illuminate\Console\Command;

class InboxRecoverStuckCommand extends Command
{
    protected $signature = 'inbox:recover-stuck';

    protected $description = 'Identifica respostas manuais presas em processamento.';

    public function handle(): int
    {
        $count = ConversationMessage::query()
            ->where('direction', 'outgoing')
            ->where('status', ConversationMessageStatus::Processing)
            ->where('updated_at', '<=', now()->subMinutes(10))
            ->update([
                'status' => ConversationMessageStatus::Unknown,
                'error_code' => 'MANUAL_REPLY_RESULT_UNKNOWN',
                'error_message' => 'Resultado da resposta manual precisa de revisao.',
                'failed_at' => now(),
            ]);

        $this->info("Mensagens presas marcadas para revisao: {$count}.");

        return self::SUCCESS;
    }
}
