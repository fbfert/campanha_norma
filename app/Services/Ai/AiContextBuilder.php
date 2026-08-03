<?php

namespace App\Services\Ai;

use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Services\SystemSettingService;

/**
 * Monta o contexto mínimo enviado ao modelo.
 *
 * Por construção não tem acesso ao model Contact: nome, telefone, etiquetas e
 * histórico de campanhas nunca entram no prompt. Mensagens de outras conversas
 * também não, porque a consulta e sempre escopada por `conversation_id`.
 */
class AiContextBuilder
{
    public function __construct(private readonly SystemSettingService $settings) {}

    /**
     * Prompt de usuário para classificação.
     */
    public function forClassification(ConversationMessage $message, ?ConversationFlowState $state): string
    {
        $parts = [];

        $question = $state?->selected_question_snapshot;
        if (filled($question)) {
            $parts[] = "PERGUNTA ENVIADA AO CONTATO:\n".$question;
        }

        $previous = $this->previousMessages($message);
        if ($previous !== []) {
            $parts[] = "MENSAGENS ANTERIORES DESTA MESMA CONVERSA:\n".implode("\n", $previous);
        }

        $parts[] = "MENSAGEM A CLASSIFICAR:\n".$this->truncate($message->body);

        if ($message->has_media) {
            $parts[] = 'OBSERVAÇÃO: a mensagem possui midia anexada que não foi enviada para análise.';
        }

        return implode("\n\n", $parts);
    }

    /**
     * Prompt de usuário para extração.
     *
     * @param  array<int, string>  $topics
     */
    public function forExtraction(ConversationMessage $message, ?ConversationFlowState $state, array $topics, string $fallbackTopic): string
    {
        $parts = [];

        $question = $state?->selected_question_snapshot;
        $parts[] = "PERGUNTA DA PESQUISA:\n".(filled($question) ? $question : 'Não registrada.');

        // Um por linha, com o vocabulário entre parênteses. Tudo numa linha so,
        // separado por vírgula, misturava identificador e sinônimo e devolvia
        // sinônimo no lugar do identificador.
        $parts[] = "TEMAS CADASTRADOS (responda com o identificador, antes dos parênteses):\n".implode("\n", array_map(
            static fn (string $topic): string => '- '.$topic,
            $topics,
        ));
        $parts[] = "TEMA DE FALLBACK (use quando nenhum outro servir):\n".$fallbackTopic;

        $previous = $this->previousMessages($message);
        if ($previous !== []) {
            $parts[] = "MENSAGENS ANTERIORES DESTA MESMA CONVERSA:\n".implode("\n", $previous);
        }

        $parts[] = "RESPOSTA A ANALISAR:\n".$this->truncate($message->body);

        return implode("\n\n", $parts);
    }

    /**
     * Poucas mensagens imediatamente anteriores, da mesma conversa, truncadas.
     *
     * @return array<int, string>
     */
    private function previousMessages(ConversationMessage $message): array
    {
        $limit = max(0, (int) $this->settings->get('ai.max_context_messages', 3));

        if ($limit === 0) {
            return [];
        }

        return ConversationMessage::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('id', '<', $message->id)
            ->whereNotNull('body')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->map(function (ConversationMessage $item): string {
                $who = $item->direction->value === 'incoming' ? 'Contato' : 'Sistema';

                return $who.': '.$this->truncate($item->body);
            })
            ->values()
            ->all();
    }

    private function truncate(?string $text): string
    {
        $max = max(100, (int) $this->settings->get('ai.max_input_chars', 2000));
        $text = trim((string) $text);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).' [...]' : $text;
    }
}
