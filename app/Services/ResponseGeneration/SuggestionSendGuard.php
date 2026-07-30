<?php

namespace App\Services\ResponseGeneration;

use App\Enums\ConversationMessageStatus;
use App\Enums\ReplySuggestionStatus;
use App\Models\ConversationMessage;
use App\Models\ConversationReplySuggestion;
use App\Services\ConversationAutomation\ConversationAutomationGuard;
use App\Services\Conversations\ConversationReplyService;
use App\Services\SystemSettingService;

/**
 * Porta unica antes de enviar uma sugestao.
 *
 * `canSend` cobre o que vale para qualquer envio, inclusive aprovado por
 * humano. `canAutoSend` acrescenta as condicoes exclusivas do autoenvio.
 *
 * Toda recusa devolve um motivo especifico, que e registrado.
 */
class SuggestionSendGuard
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly ConversationReplyService $replies,
        private readonly ConversationAutomationGuard $automation,
        private readonly ResponseModeResolver $modes,
        private readonly ReplyTextValidator $validator,
    ) {}

    /**
     * Condicoes comuns a qualquer envio de sugestao.
     *
     * @return array{allowed: bool, reason: ?string}
     */
    public function canSend(ConversationReplySuggestion $suggestion): array
    {
        if (! $suggestion->status->isLive()) {
            return $this->deny('sugestao_nao_esta_viva');
        }

        if (! $suggestion->action->producesText() || $suggestion->outgoingText() === '') {
            return $this->deny('sugestao_sem_texto');
        }

        if ($suggestion->isExpired()) {
            return $this->deny('sugestao_expirada');
        }

        // Obsolescencia por fato: chegou mensagem nova depois da que originou.
        if ($suggestion->isStale()) {
            return $this->deny('sugestao_obsoleta');
        }

        $eligible = $this->replies->contactEligible($suggestion->conversation);
        if (! $eligible['allowed']) {
            return $this->deny($eligible['reason']);
        }

        $state = $suggestion->state;
        if ($state && $state->is_paused) {
            return $this->deny('conversa_pausada');
        }

        if ($state && $state->current_stage->isTerminal()) {
            return $this->deny('fluxo_encerrado');
        }

        if (! $this->modes->forFlow($suggestion->flow)->allowsSending()) {
            return $this->deny('modo_nao_permite_envio');
        }

        if ($this->hasPendingOutgoing($suggestion)) {
            return $this->deny('mensagem_pendente_existente');
        }

        // O texto final, editado ou nao, precisa passar pelo validador.
        $validation = $this->validator->validate($suggestion->outgoingText());
        if (! $validation['valid']) {
            return $this->deny('texto_reprovado:'.implode(',', $validation['errors']));
        }

        // Etapa 9D. Sugestao reprovada na fundamentacao ja nasce bloqueada, entao
        // esta condicao so e alcancada se alguem reabrir a sugestao depois. E
        // exatamente por isso que ela existe: o veredito grava o motivo na linha,
        // e a porta de envio confere a linha, nao a memoria de quem gerou.
        if ($suggestion->grounding_status !== null && ! $suggestion->grounding_status->allowsSending()) {
            return $this->deny('fundamentacao_reprovada:'.$suggestion->grounding_status->value);
        }

        return $this->allow();
    }

    /**
     * Condicoes exclusivas do autoenvio, alem de todas as anteriores.
     *
     * @return array{allowed: bool, reason: ?string}
     */
    public function canAutoSend(ConversationReplySuggestion $suggestion): array
    {
        $common = $this->canSend($suggestion);
        if (! $common['allowed']) {
            return $common;
        }

        if (! $this->modes->forFlow($suggestion->flow)->allowsAutoSend()) {
            return $this->deny('autoenvio_desabilitado');
        }

        if ($suggestion->requires_human_review || $suggestion->handoff_reason !== null) {
            return $this->deny('sinalizado_para_revisao');
        }

        $classification = $suggestion->classification?->classification?->value;
        if ($classification === null || ! in_array($classification, $this->allowlist(), true)) {
            return $this->deny('categoria_fora_da_allowlist');
        }

        $threshold = (float) $this->settings->get('ai.response.auto_send_min_confidence', 0.9);
        if ($suggestion->confidence === null || $suggestion->confidence < $threshold) {
            return $this->deny('confianca_insuficiente');
        }

        $state = $suggestion->state;
        if ($state) {
            $automation = $this->automation->canSend($state);
            if (! $automation['allowed']) {
                return $this->deny($automation['reason']);
            }

            if ($state->followups_count >= $this->turnLimit($suggestion)) {
                return $this->deny('limite_de_aprofundamentos');
            }
        }

        // Conversa assumida por uma pessoa nao recebe envio automatico, a menos
        // que a automacao esteja explicitamente autorizada nesse caso.
        $assigned = $suggestion->conversation?->assigned_user_id;
        $allowAssigned = (bool) $this->settings->get('ai.response.auto_send_when_assigned', '0');
        if ($assigned !== null && ! $allowAssigned) {
            return $this->deny('conversa_atribuida_a_humano');
        }

        return $this->allow();
    }

    public function turnLimit(ConversationReplySuggestion $suggestion): int
    {
        $global = max(0, (int) $this->settings->get('ai.response.max_followups', 2));
        $flow = $suggestion->flow?->max_followups;

        // O limite do fluxo so restringe quando for menor e estiver definido.
        return $flow !== null && $flow > 0 ? min($global, (int) $flow) : $global;
    }

    /** @return array<int, string> */
    public function allowlist(): array
    {
        $raw = (string) $this->settings->get('ai.response.auto_send_classifications', '');

        return collect(explode('|', $raw))
            ->map(fn (string $item): string => trim($item))
            ->filter(fn (string $item): bool => $item !== '')
            ->values()
            ->all();
    }

    private function hasPendingOutgoing(ConversationReplySuggestion $suggestion): bool
    {
        return ConversationMessage::query()
            ->where('conversation_id', $suggestion->conversation_id)
            ->where('direction', 'outgoing')
            ->whereIn('status', [
                ConversationMessageStatus::Pending->value,
                ConversationMessageStatus::Processing->value,
            ])
            ->exists();
    }

    /** @return array{allowed: bool, reason: ?string} */
    private function allow(): array
    {
        return ['allowed' => true, 'reason' => null];
    }

    /** @return array{allowed: bool, reason: ?string} */
    private function deny(?string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason];
    }

    /**
     * Status a aplicar quando o envio e recusado de forma definitiva.
     */
    public function statusForRefusal(string $reason): ReplySuggestionStatus
    {
        return match (true) {
            str_starts_with($reason, 'sugestao_obsoleta') => ReplySuggestionStatus::Superseded,
            str_starts_with($reason, 'sugestao_expirada') => ReplySuggestionStatus::Expired,
            default => ReplySuggestionStatus::Blocked,
        };
    }
}
