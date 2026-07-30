<?php

namespace App\Console\Commands;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Services\SystemSettingService;
use Illuminate\Console\Command;

class InboxArchiveResolvedCommand extends Command
{
    protected $signature = 'inbox:archive-resolved';

    protected $description = 'Arquiva conversas resolvidas conforme configuração.';

    public function handle(SystemSettingService $settings): int
    {
        $days = (int) $settings->get('inbox.archive_resolved_after_days', 30);
        $count = Conversation::where('status', ConversationStatus::Resolved)
            ->where('updated_at', '<=', now()->subDays($days))
            ->update([
                'status' => ConversationStatus::Archived,
                'is_archived' => true,
                'archived_at' => now(),
            ]);

        $this->info("Conversas arquivadas: {$count}.");

        return self::SUCCESS;
    }
}
