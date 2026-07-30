<?php

namespace App\Contracts;

use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\ConversationReplySuggestion;

interface ConversationResponseGenerator
{
    /**
     * Gera uma sugestao de resposta para a mensagem recebida.
     *
     * Retorna null quando nada foi produzido: modo desligado, provedor
     * indisponivel ou saida invalida. Nunca envia nada.
     *
     * @param  array<string, mixed>  $options
     */
    public function generate(
        ConversationMessage $message,
        ConversationFlowState $state,
        array $options = [],
    ): ?ConversationReplySuggestion;
}
