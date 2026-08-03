<?php

namespace App\Jobs;

use App\Models\ConversationEvent;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Services\Ai\AudioTranscriptionService;
use App\Services\ConversationAutomation\ConversationAutomatedReplyService;
use App\Services\Conversations\ConversationEventService;
use App\Services\SystemSettingService;
use App\Services\WhatsApp\WhatsAppServiceClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Transcreve um áudio recebido e devolve a mensagem ao fluxo.
 *
 * Áudio chega com corpo vazio, e o motor so avalia texto: sem este job, quem
 * fala não e ouvido pelo sistema. Depois da transcrição, a mensagem passa a ter
 * texto aproveitável e segue o mesmo caminho de qualquer resposta escrita.
 *
 * O arquivo nunca e gravado em disco: ele existe em memória durante a chamada e
 * some com o fim do job. O que fica e a transcrição.
 */
class TranscribeIncomingAudioJob implements ShouldQueue
{
    use Queueable;

    /** Marca no histórico: o pedido por escrito sai uma vez por conversa. */
    private const ASKED_FOR_TEXT_EVENT = 'audio_reply_asked';

    public function __construct(private readonly int $messageId)
    {
        $this->onQueue(app(SystemSettingService::class)->get('ai.transcription.queue', 'ai-interpretation'));
    }

    public function handle(
        AudioTranscriptionService $transcricao,
        WhatsAppServiceClient $whatsapp,
        SystemSettingService $settings,
    ): void {
        $mensagem = ConversationMessage::query()->with('conversation')->find($this->messageId);

        if (! $mensagem || ! $mensagem->has_media || filled($mensagem->body)) {
            return;
        }

        // Transcrição desligada: o áudio não vira texto, e ficar calado faria a
        // pessoa achar que falou sozinha. Ela precisa saber que foi recebida e
        // que o caminho e escrever.
        if (! $transcricao->enabled()) {
            $this->askForText($mensagem);

            return;
        }

        $chatId = $mensagem->external_chat_id ?: $mensagem->conversation?->external_chat_id;

        if (blank($chatId) || blank($mensagem->external_message_id)) {
            $this->askForText($mensagem);

            return;
        }

        try {
            $midia = $whatsapp->fetchMessageMedia($chatId, (string) $mensagem->external_message_id, [
                'maxBytes' => (int) $settings->get('ai.transcription.max_bytes', 16777216),
            ]);
        } catch (Throwable $excecao) {
            // Mídia indisponível não e erro de operação: a sessão do WhatsApp
            // guarda por tempo limitado, e áudio antigo simplesmente não volta.
            Log::warning('transcription.media_unavailable', [
                'message_id' => $mensagem->id,
                'error' => $excecao->getMessage(),
            ]);

            $this->askForText($mensagem);

            return;
        }

        $conteudo = base64_decode((string) ($midia['data'] ?? ''), true);

        if ($conteudo === false || $conteudo === '') {
            $this->askForText($mensagem);

            return;
        }

        $registro = $transcricao->transcribe($mensagem, $conteudo, $this->filename($midia));

        // Sem texto aproveitável não ha o que reavaliar: áudio sem fala não e
        // resposta, e insistir criaria uma pergunta sobre o silêncio. Mas a
        // pessoa continua merecendo saber que o áudio chegou e não foi
        // entendido.
        if (! $registro?->usableAsAnswer()) {
            $this->askForText($mensagem);

            return;
        }

        EvaluateConversationFlowJob::dispatch($mensagem->id);
    }

    /**
     * Pede o conteúdo por escrito.
     *
     * Sai uma vez por conversa: quem manda três áudios seguidos não precisa
     * receber o mesmo pedido três vezes, e repetir soaria como recusa.
     *
     * O texto convida em vez de recusar. A pessoa escolheu falar porque
     * escrever custa mais, e um "não consigo ouvir" seco tende a encerrar a
     * conversa ali.
     */
    private function askForText(ConversationMessage $mensagem): void
    {
        $conversa = $mensagem->conversation;
        $state = $conversa ? ConversationFlowState::query()->where('conversation_id', $conversa->id)->first() : null;

        if (! $conversa || ! $state || $state->is_paused || $state->current_stage->isTerminal()) {
            return;
        }

        $texto = trim((string) app(SystemSettingService::class)->get('conversation_automation.audio_reply_text', ''));

        if ($texto === '') {
            return;
        }

        $jaPedido = ConversationEvent::query()
            ->where('conversation_id', $conversa->id)
            ->where('event_type', self::ASKED_FOR_TEXT_EVENT)
            ->exists();

        if ($jaPedido) {
            return;
        }

        $enviada = app(ConversationAutomatedReplyService::class)->queue($state, $texto, 'audio_reply_queued');

        if ($enviada) {
            app(ConversationEventService::class)->record($conversa, self::ASKED_FOR_TEXT_EVENT, 'Pedido de resposta por escrito enviado.', $mensagem);
        }
    }

    /**
     * O provedor infere o formato pela extensão, então o nome importa.
     */
    private function filename(array $midia): string
    {
        $mime = (string) ($midia['mimetype'] ?? '');

        $extensao = match (true) {
            str_contains($mime, 'ogg') => 'ogg',
            str_contains($mime, 'mpeg'), str_contains($mime, 'mp3') => 'mp3',
            str_contains($mime, 'wav') => 'wav',
            str_contains($mime, 'mp4'), str_contains($mime, 'm4a') => 'm4a',
            default => 'ogg',
        };

        return 'audio-'.$this->messageId.'.'.$extensao;
    }
}
