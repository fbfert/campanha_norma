<?php

namespace App\Services\KeywordCampaigns;

use App\Enums\ConversationFlowStage;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Services\AuditLogger;
use App\Services\ConversationAutomation\ConversationFlowService;
use App\Services\ConversationAutomation\ConversationFlowStateMachine;
use App\Services\SystemSettingService;

/**
 * Abre a pesquisa da campanha, reabrindo quando a conversa já teve uma.
 *
 * `conversation_flow_states` tem chave única por conversa, e
 * `activateForConversation` devolve o estado existente em vez de recomeçar. É
 * o certo para o lote, que não quer atropelar pesquisa alguma; é o errado para
 * a campanha, porque conversa que já teve pesquisa é a maioria da base — e o
 * efeito seria a pessoa ouvir "posso te fazer uma pergunta?" e o "sim" dela
 * não ir a lugar nenhum.
 *
 * A reabertura só alcança pesquisa morta: encerrada ou com o prazo vencido.
 * Pesquisa viva é intocável.
 */
class CampaignSurveyStarter
{
    public function __construct(
        private readonly ConversationFlowService $flows,
        private readonly ConversationFlowStateMachine $machine,
        private readonly SystemSettingService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Existe pesquisa acontecendo aqui agora?
     *
     * É a pergunta que decide se o convite sai junto com a confirmação.
     */
    public function temPesquisaViva(?int $conversationId): bool
    {
        if ($conversationId === null) {
            return false;
        }

        $estado = ConversationFlowState::query()->where('conversation_id', $conversationId)->first();

        return $estado !== null && $estado->estaViva();
    }

    /**
     * Coloca a conversa esperando permissão, com o fluxo da campanha.
     *
     * Devolve nulo quando não abriu — pesquisa viva, contato inelegível ou
     * conversa sem contato.
     */
    public function abrir(Conversation $conversation, ConversationFlow $flow, ConversationMessage $inbound): ?ConversationFlowState
    {
        $existente = ConversationFlowState::query()->where('conversation_id', $conversation->id)->first();

        if ($existente === null) {
            $estado = $this->flows->activateForConversation($conversation, $flow);

            if ($estado === null) {
                return null;
            }

            return $this->marcarMensagemComoProcessada($estado, $inbound);
        }

        if ($existente->estaViva()) {
            return null;
        }

        return $this->reabrir($existente, $flow, $inbound);
    }

    /**
     * Reaproveita a linha, porque a chave única não deixa criar outra.
     *
     * O que se perde é o estágio e os contadores da pesquisa anterior. O que
     * fica: as transições, os insights e as mensagens, que moram em tabelas
     * próprias e não são tocados aqui. Quem quiser saber o que foi respondido
     * antes continua conseguindo.
     */
    private function reabrir(ConversationFlowState $estado, ConversationFlow $flow, ConversationMessage $inbound): ConversationFlowState
    {
        $anterior = $estado->only(['conversation_flow_id', 'current_stage', 'end_reason', 'started_at', 'completed_at']);

        $estado->forceFill([
            'conversation_flow_id' => $flow->id,
            'current_stage' => ConversationFlowStage::Inactive,
            'stage_before_hold' => null,
            'selected_question_id' => null,
            'selected_question_snapshot' => null,
            'question_reasked_at' => null,
            'automated_messages_count' => 1,
            'followups_count' => 0,
            'attempts_count' => 0,
            'last_processed_message_id' => $inbound->id,
            'last_automated_message_id' => null,
            'end_reason' => null,
            'is_paused' => false,
            'needs_human_review' => false,
            'started_at' => now(),
            'completed_at' => null,
            'expires_at' => now()->addHours(max(1, (int) ($flow->validity_hours
                ?: $this->settings->get('conversation_automation.default_validity_hours', 48)))),
        ])->save();

        $estado->setRelation('flow', $flow);

        $this->machine->transition($estado, ConversationFlowStage::InitialMessageSent, 'keyword_campaign_survey_reopened');
        $this->machine->transition($estado, ConversationFlowStage::WaitingPermission, 'awaiting_permission');

        $this->audit->log(
            'keyword_campaign.survey_reopened',
            'Pesquisa reaberta por campanha por palavra-chave.',
            $estado,
            $anterior,
            ['conversation_flow_id' => $flow->id, 'conversation_id' => $estado->conversation_id],
        );

        return $estado->refresh();
    }

    /**
     * A palavra-chave já nasce marcada como processada.
     *
     * Sem isso, a mensagem que originou a inscrição seria avaliada pelo motor
     * como resposta ao pedido de permissão — e "quero o sorteio" classificado
     * como permissão concedida dispararia a pergunta sem ninguém ter dito sim.
     */
    private function marcarMensagemComoProcessada(ConversationFlowState $estado, ConversationMessage $inbound): ConversationFlowState
    {
        if ($estado->last_processed_message_id === null) {
            $estado->forceFill(['last_processed_message_id' => $inbound->id])->save();
        }

        return $estado;
    }
}
