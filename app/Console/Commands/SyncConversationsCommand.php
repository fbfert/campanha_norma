<?php

namespace App\Console\Commands;

use App\Enums\ConversationSyncStatus;
use App\Jobs\SyncWhatsAppConversationsJob;
use App\Models\ConversationSyncRun;
use App\Services\Conversations\ConversationSyncService;
use App\Services\SystemSettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncConversationsCommand extends Command
{
    protected $signature = 'conversations:sync {--chat=} {--days= : Dias retroativos} {--limit-chats= : Limite de chats} {--messages-per-chat= : Limite de mensagens por chat} {--queue : Despachar para fila}';

    protected $description = 'Sincroniza conversas individuais disponíveis na sessão atual do WhatsApp Web.';

    public function handle(ConversationSyncService $sync, SystemSettingService $settings): int
    {
        if (! (bool) $settings->get('conversations.sync_enabled', true)) {
            $this->info('Sincronização de conversas desativada.');

            return self::SUCCESS;
        }

        $lock = Cache::lock('conversations:sync:active', $this->option('queue') ? 1 : 600);
        if ($lock->get() === false) {
            $this->warn('Já existe uma sincronização em andamento.');

            return self::FAILURE;
        }

        $options = $sync->sanitizeOptions([
            'chat' => $this->option('chat'),
            'days' => $this->option('days'),
            'limit_chats' => $this->option('limit-chats'),
            'messages_per_chat' => $this->option('messages-per-chat'),
        ]);

        $run = ConversationSyncRun::create([
            'status' => ConversationSyncStatus::Pending,
            'options' => $options,
        ]);

        if ($this->option('queue')) {
            $lock->release();
            SyncWhatsAppConversationsJob::dispatch($run->id)->onQueue('whatsapp-conversation-sync');
            $this->info("Sincronização {$run->id} enviada para fila.");

            return self::SUCCESS;
        }

        try {
            $result = $sync->run($run, $options);
        } finally {
            $lock->release();
        }
        $this->info("Status: {$result->status->label()}");
        $this->line("Chats: {$result->chats_processed}/{$result->chats_found}");
        $this->line("Mensagens importadas: {$result->messages_imported}");
        $this->line("Mensagens ignoradas: {$result->messages_skipped}");
        $this->line("Falhas: {$result->messages_failed}");

        return $result->status === ConversationSyncStatus::Failed ? self::FAILURE : self::SUCCESS;
    }
}
