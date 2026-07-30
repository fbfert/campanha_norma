<?php

namespace App\Jobs;

use App\Models\ConversationMessage;
use App\Services\ConversationAutomation\ConversationFlowService;
use App\Services\SystemSettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Avalia o fluxo conversacional após uma mensagem recebida.
 * Nunca envia diretamente: apenas decide e cria mensagem pendente pelo serviço.
 */
class EvaluateConversationFlowJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public function __construct(private readonly int $conversationMessageId)
    {
        $this->onQueue(app(SystemSettingService::class)->get('conversation_automation.queue', 'conversation-automation'));
    }

    public function handle(ConversationFlowService $flows): void
    {
        $message = ConversationMessage::with('conversation.contact')->find($this->conversationMessageId);

        if (! $message) {
            return;
        }

        // Trava por conversa para não permitir dois workers avaliando o mesmo fluxo.
        $lock = Cache::lock("conversation-flow:{$message->conversation_id}", 60);

        if (! $lock->get()) {
            $this->release(10);

            return;
        }

        try {
            $flows->handleIncomingMessage($message);
        } finally {
            $lock->release();
        }
    }
}
