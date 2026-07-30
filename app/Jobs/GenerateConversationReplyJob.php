<?php

namespace App\Jobs;

use App\Models\ConversationMessage;
use App\Services\ResponseGeneration\ConversationSuggestionService;
use App\Services\SystemSettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Gera a sugestao de resposta em fila propria.
 *
 * O atraso configuravel agrupa mensagens consecutivas: ao executar, o servico
 * verifica se esta mensagem ainda e a ultima recebida e desiste se nao for.
 */
class GenerateConversationReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(private readonly int $conversationMessageId)
    {
        $this->onQueue(app(SystemSettingService::class)->get('ai.response.queue', 'ai-response-generation'));
    }

    public function handle(ConversationSuggestionService $suggestions): void
    {
        $message = ConversationMessage::with('conversation.contact')->find($this->conversationMessageId);

        if (! $message) {
            return;
        }

        $lock = Cache::lock("ai-response:{$message->conversation_id}", 180);

        if (! $lock->get()) {
            $this->release(15);

            return;
        }

        try {
            $suggestions->handleIncoming($message);
        } finally {
            $lock->release();
        }
    }
}
