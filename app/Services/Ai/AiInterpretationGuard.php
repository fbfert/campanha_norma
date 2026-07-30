<?php

namespace App\Services\Ai;

use App\Enums\ContactStatus;
use App\Enums\ConversationMessageDirection;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Services\SystemSettingService;

/**
 * Porta única antes de qualquer interpretação.
 *
 * As chaves são deliberadamente separadas por responsabilidade, para que uma
 * única configuração nunca misture motor de fluxo, análise por IA e futura
 * geração de respostas:
 *
 *   conversation_automation.enabled     motor determinístico da 9A
 *   ai.enabled                          chave mestra da infraestrutura de IA
 *   ai.analysis_enabled                 classificação e extração da 9B
 *   ai.response_generation_enabled      reservado para a 9C, sempre desligado aqui
 *   ai.auto_send_enabled                reservado para a 9C, sempre desligado aqui
 *
 * Devolve sempre um motivo legível, para auditoria e para a tela de revisão.
 */
class AiInterpretationGuard
{
    public function __construct(private readonly SystemSettingService $settings) {}

    /**
     * Chave mestra da infraestrutura de IA. Sozinha não habilita nada.
     */
    public function enabled(): bool
    {
        return (bool) $this->settings->get('ai.enabled', '0');
    }

    /**
     * Habilita a análise da 9B: classificação e extração.
     */
    public function analysisEnabled(): bool
    {
        return $this->enabled() && (bool) $this->settings->get('ai.analysis_enabled', '0');
    }

    public function classificationEnabled(): bool
    {
        return $this->analysisEnabled() && (bool) $this->settings->get('ai.classification_enabled', '1');
    }

    public function extractionEnabled(): bool
    {
        return $this->analysisEnabled() && (bool) $this->settings->get('ai.extraction_enabled', '1');
    }

    /**
     * Reservado para a Etapa 9C. Nenhum caminho de código da 9B consulta este
     * valor para decidir alguma coisa: ele existe para que a separação de
     * responsabilidades já esteja explícita e auditável.
     */
    public function responseGenerationEnabled(): bool
    {
        return $this->enabled() && (bool) $this->settings->get('ai.response_generation_enabled', '0');
    }

    /**
     * Reservado para a Etapa 9C. Envio automático de resposta gerada por IA.
     */
    public function autoSendEnabled(): bool
    {
        return $this->responseGenerationEnabled() && (bool) $this->settings->get('ai.auto_send_enabled', '0');
    }

    /**
     * @return array{allowed: bool, reason: ?string}
     */
    public function canInterpret(ConversationMessage $message, ?ConversationFlowState $state): array
    {
        if (! $this->enabled()) {
            return $this->deny('ia_desabilitada');
        }

        if (! $this->analysisEnabled()) {
            return $this->deny('analise_desabilitada');
        }

        if ($message->direction !== ConversationMessageDirection::Incoming) {
            return $this->deny('mensagem_nao_recebida');
        }

        // A 9B interpreta respostas de pesquisa, não conversas avulsas.
        if (! $state) {
            return $this->deny('sem_contexto_de_pesquisa');
        }

        $contact = $message->conversation?->contact;

        if ($contact && $contact->do_not_contact) {
            return $this->deny('contato_nao_contatar');
        }

        if ($contact && $contact->status !== ContactStatus::Active) {
            return $this->deny('contato_inativo');
        }

        if ($state->is_paused) {
            return $this->deny('conversa_pausada');
        }

        return $this->allow();
    }

    /** @return array{allowed: bool, reason: ?string} */
    private function allow(): array
    {
        return ['allowed' => true, 'reason' => null];
    }

    /** @return array{allowed: bool, reason: ?string} */
    private function deny(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason];
    }
}
