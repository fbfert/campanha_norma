<?php

namespace App\Jobs;

use App\Models\ConversationMessage;
use App\Services\Ai\ImageDescriptionService;
use App\Services\ConversationAutomation\UnreadableMediaResponder;
use App\Services\SystemSettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Descreve uma imagem recebida e devolve a mensagem ao fluxo.
 *
 * Mesmo desenho do áudio: a mídia chega sem texto, a leitura corre em fila
 * própria e, dando certo, devolve a mensagem ao motor — que agora enxerga a
 * descrição por `readableText()`.
 *
 * Não dando certo, a pessoa continua merecendo saber que a imagem chegou e que
 * o caminho é escrever. O silêncio é a única saída que não pode acontecer.
 */
class DescribeIncomingImageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(private readonly int $messageId)
    {
        $this->onQueue(app(SystemSettingService::class)->get('ai.vision.queue', 'ai-interpretation'));
    }

    public function handle(ImageDescriptionService $vision): void
    {
        $message = ConversationMessage::with('conversation')->find($this->messageId);

        if (! $message) {
            return;
        }

        // Uma leitura por mensagem: duas execuções em paralelo pagariam duas
        // vezes pela mesma imagem e gravariam duas descrições.
        $lock = Cache::lock("image-description:{$message->id}", 180);

        if (! $lock->get()) {
            $this->release(15);

            return;
        }

        try {
            $descricao = $vision->describe($message);

            if ($descricao?->usableAsAnswer()) {
                EvaluateConversationFlowJob::dispatch($message->id);

                return;
            }

            /*
             | Sem descrição aproveitável, o piso volta a valer.
             |
             | Figurinha de "bom dia" não é resposta, e insistir criaria uma
             | pergunta sobre o nada. Mas quem mandou uma foto que não
             | conseguimos ler precisa saber disso — foi o silêncio absoluto
             | que fez uma figurinha ficar dois dias sem retorno.
             */
            app(UnreadableMediaResponder::class)->askForText($message, 'conversation_automation.media_reply_text');
        } finally {
            $lock->release();
        }
    }
}
