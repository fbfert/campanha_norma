<?php

namespace App\Services\ConversationAutomation;

use App\Enums\ConversationMessageDirection;
use App\Models\ConversationMessage;

/**
 * A mensagem em que a reação foi feita.
 *
 * Reagir só significa alguma coisa quando se sabe em quê. Um 👍 dado numa
 * mensagem nossa de três semanas atrás não autoriza a pergunta de hoje, e um
 * 👍 dado na própria mensagem da pessoa não é resposta a nós — é ela
 * concordando consigo mesma.
 *
 * Este serviço só localiza e confere o alvo. Se aquele emoji quer dizer sim ou
 * não é assunto de `ReactionClassifier`.
 */
class ReactionTargetResolver
{
    /**
     * A mensagem nossa que a reação atingiu, ou `null`.
     *
     * Devolve `null` também quando o alvo existe mas é mensagem recebida: o
     * WhatsApp deixa reagir na própria mensagem, e isso não decide nada aqui.
     */
    public function alvo(ConversationMessage $reaction): ?ConversationMessage
    {
        if (! $reaction->isReaction()) {
            return null;
        }

        $alvo = $reaction->reactedTo();

        if (! $alvo || $alvo->direction !== ConversationMessageDirection::Outgoing) {
            return null;
        }

        return $alvo;
    }

    /**
     * A reação foi feita na última coisa que dissemos?
     *
     * É assim que "a mensagem que perguntou" fica identificável sem depender de
     * onde a pergunta nasceu. Fluxo que começa por lote tem a pergunta na
     * mensagem do lote; fluxo que continua sozinho tem a pergunta na resposta
     * automática. As duas são, no momento em que a reação chega, a última
     * mensagem nossa da conversa.
     *
     * A comparação usa apenas o que existia antes da reação: se enquanto o
     * emoji vinha nós enviamos outra coisa, a pergunta que a pessoa respondeu
     * continua sendo a de antes.
     */
    public function ehAUltimaMensagemNossa(ConversationMessage $reaction, ConversationMessage $alvo): bool
    {
        $ultima = $reaction->ultimaMensagemNossaAntes();

        return $ultima !== null && (int) $ultima->id === (int) $alvo->id;
    }

    /**
     * O alvo, quando ele é a última mensagem nossa. Atalho para quem precisa
     * das duas condições juntas, que é o caso do estágio de permissão.
     */
    public function alvoQuePerguntou(ConversationMessage $reaction): ?ConversationMessage
    {
        $alvo = $this->alvo($reaction);

        if (! $alvo || ! $this->ehAUltimaMensagemNossa($reaction, $alvo)) {
            return null;
        }

        return $alvo;
    }
}
