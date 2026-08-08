<?php

namespace App\Contracts;

/**
 * Provedor que devolve conversas e mensagens já existentes.
 *
 * O WhatsApp Web lê o histórico do aparelho, e é isso que permite a
 * sincronização periódica recuperar mensagem que o webhook perdeu. A API
 * oficial da Meta não tem equivalente: chega o que o webhook entregar, e o que
 * se perdeu está perdido.
 *
 * Separado justamente para que essa perda seja visível quando o provedor mudar,
 * em vez de virar um método que devolve lista vazia e faz a sincronização
 * parecer bem-sucedida.
 */
interface ReadsConversationHistory
{
    /** @return array<string, mixed> */
    public function listConversations(array $options = []): array;

    /** @return array<string, mixed> */
    public function fetchConversationMessages(string $externalChatId, array $options = []): array;
}
