<?php

namespace App\Services\Conversations;

use App\Enums\ConversationMessageDirection;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Carbon\CarbonInterface;

/**
 * Reconhece o eco de uma mensagem que nós mesmos enviamos.
 *
 * O WhatsApp Web às vezes entrega a mensagem e ainda assim lança erro, sem
 * devolver o identificador — o serviço Node já trata esse caso e responde com
 * `external_message_id` nulo. A linha fica gravada sem id, e quando o eco
 * chega não há por onde casar os dois: a mesma frase aparece duas vezes na
 * conversa e entra duas vezes no contexto que vai para o modelo.
 *
 * A regra mora aqui, e não em quem chama, porque o eco entra por duas portas: a
 * sincronização periódica e o webhook ao vivo. Corrigi a primeira e deixei a
 * segunda, e o problema continuou acontecendo exatamente igual — só que pela
 * porta que não tinha sido tratada. Uma regra em dois lugares é uma regra que
 * vai ser consertada em um deles.
 */
class OutgoingEchoMatcher
{
    /**
     * Folga para reconhecer o eco.
     *
     * O eco costuma voltar em segundos, mas a sincronização pode rodar bem
     * depois. Dez minutos cobrem o atraso sem alcançar um reenvio deliberado do
     * mesmo texto, que é o único caso em que duas linhas iguais são certas.
     */
    private const WINDOW_MINUTES = 10;

    /**
     * Adota a linha órfã, se houver, preenchendo o identificador que faltava.
     *
     * @return bool `true` quando o eco foi absorvido e não deve virar linha nova
     */
    public function adopt(Conversation $conversation, ?string $body, ?string $externalMessageId, ?string $externalChatId, CarbonInterface $occurredAt): bool
    {
        if (blank($body) || blank($externalMessageId)) {
            return false;
        }

        $orfa = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', ConversationMessageDirection::Outgoing)
            ->whereNull('external_message_id')
            ->where('body', $body)
            ->whereBetween('created_at', [
                $occurredAt->copy()->subMinutes(self::WINDOW_MINUTES),
                $occurredAt->copy()->addMinutes(self::WINDOW_MINUTES),
            ])
            ->orderBy('id')
            ->first();

        if (! $orfa) {
            return false;
        }

        $orfa->forceFill([
            'external_message_id' => $externalMessageId,
            'external_chat_id' => $orfa->external_chat_id ?: $externalChatId,
        ])->save();

        return true;
    }
}
