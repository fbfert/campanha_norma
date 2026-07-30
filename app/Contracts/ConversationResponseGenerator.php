<?php

namespace App\Contracts;

use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\ConversationReplySuggestion;

interface ConversationResponseGenerator
{
    /**
     * Gera uma sugestão de resposta para a mensagem recebida.
     *
     * Retorna null quando nada foi produzido: modo desligado, provedor
     * indisponível ou saída invalida. Nunca envia nada.
     *
     * @param  array<string, mixed>  $options
     */
    public function generate(
        ConversationMessage $message,
        ConversationFlowState $state,
        array $options = [],
    ): ?ConversationReplySuggestion;
}
