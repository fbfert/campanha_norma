<?php

namespace App\Jobs;

use App\Models\ConversationMessage;
use App\Services\Ai\ConversationInterpretationService;
use App\Services\SystemSettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Interpreta uma mensagem recebida em fila própria.
 *
 * Nunca envia nada: apenas classifica, extrai e sinaliza revisão. O timeout e
 * maior que o timeout do provedor para que o estouro de tempo do HTTP falhe
 * antes do job.
 */
class InterpretConversationMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(private readonly int $conversationMessageId)
    {
        $this->onQueue(app(SystemSettingService::class)->get('ai.queue', 'ai-interpretation'));
    }

    public function handle(ConversationInterpretationService $interpretation): void
    {
        $message = ConversationMessage::with('conversation.contact')->find($this->conversationMessageId);

        if (! $message) {
            return;
        }

        // Trava por conversa para não permitir dois workers interpretando a
        // mesma conversa ao mesmo tempo.
        $lock = Cache::lock("ai-interpretation:{$message->conversation_id}", 180);

        if (! $lock->get()) {
            $this->release(15);

            return;
        }

        try {
            $interpretation->interpret($message);
        } finally {
            $lock->release();
        }
    }
}
