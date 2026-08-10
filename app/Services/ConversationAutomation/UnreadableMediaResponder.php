<?php

namespace App\Services\ConversationAutomation;

use App\Enums\ConversationMessageDirection;
use App\Models\ConversationEvent;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Services\Conversations\ConversationEventService;
use App\Services\SystemSettingService;

/**
 * Responde a mensagem que o sistema recebe mas não consegue ler.
 *
 * Áudio, figurinha, imagem, vídeo e documento chegam sem texto. O motor de
 * fluxo só avalia `text`, e a transcrição só trata áudio: figurinha e imagem
 * não caíam em lugar nenhum e produziam silêncio absoluto. Uma figurinha ficou
 * dois dias sem retorno, e a conversa só voltou porque a pessoa escreveu de
 * novo por conta própria.
 *
 * Não é ler — é não deixar no vácuo. Quem manda uma foto precisa saber que
 * chegou e que o caminho é escrever.
 *
 * O pedido sai **uma vez por conversa**. Quem prefere mandar áudio vai mandar
 * de novo, e repetir a mesma frase a cada tentativa troca o silêncio por outro
 * incômodo.
 */
class UnreadableMediaResponder
{
    /** Marca no histórico; é por ela que o pedido sai uma vez só. */
    public const ASKED_FOR_TEXT_EVENT = 'asked_for_text_reply';

    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly ConversationAutomatedReplyService $replies,
        private readonly ConversationEventService $events,
    ) {}

    /**
     * Mídia que chegou e que o sistema não tem como ler.
     *
     * A regra mora aqui, e não em quem recebe, porque há **dois** caminhos de
     * entrada: o webhook e a sincronização do histórico. Ela nasceu só no
     * webhook, e por isso um áudio que entrou pela sincronização passou direto —
     * o João Pedro mandou um áudio no dia 07 e recebeu "já te respondo" no dia
     * 10, sem nunca saber que não conseguimos ouvi-lo. É o mesmo formato de
     * defeito do eco de saída duplicado, que também precisou existir nos dois
     * caminhos.
     *
     * Áudio fica de fora: tem caminho próprio, com transcrição, e só cai aqui se
     * ela falhar.
     */
    public function handles(ConversationMessage $mensagem): bool
    {
        return $mensagem->direction === ConversationMessageDirection::Incoming
            && $mensagem->has_media
            && ! in_array($mensagem->message_type, ['ptt', 'audio', 'text'], true);
    }

    /**
     * @param  string  $settingKey  Configuração com o texto desta mídia. Áudio e
     *                              imagem pedem frases diferentes: "não consigo
     *                              escutar" não serve para uma foto.
     */
    public function askForText(ConversationMessage $mensagem, string $settingKey): bool
    {
        $conversa = $mensagem->conversation;
        $state = $conversa ? ConversationFlowState::query()->where('conversation_id', $conversa->id)->first() : null;

        if (! $conversa || ! $state || $state->is_paused || $state->current_stage->isTerminal()) {
            return false;
        }

        $texto = trim((string) $this->settings->get($settingKey, ''));

        if ($texto === '' || $this->alreadyAsked($conversa->id)) {
            return false;
        }

        $enviada = $this->replies->queue($state, $texto, 'unreadable_media_reply_queued');

        if (! $enviada) {
            return false;
        }

        $this->events->record($conversa, self::ASKED_FOR_TEXT_EVENT, 'Pedido de resposta por escrito enviado.', $mensagem);

        return true;
    }

    private function alreadyAsked(int $conversationId): bool
    {
        return ConversationEvent::query()
            ->where('conversation_id', $conversationId)
            ->where('event_type', self::ASKED_FOR_TEXT_EVENT)
            ->exists();
    }
}
