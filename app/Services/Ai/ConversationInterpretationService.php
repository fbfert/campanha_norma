<?php

namespace App\Services\Ai;

use App\Enums\MessageClassification;
use App\Models\ConversationFlowState;
use App\Models\ConversationInsight;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageClassification;
use App\Services\Conversations\ConversationEventService;
use App\Services\SystemSettingService;

/**
 * Orquestrador do pipeline de interpretação da subetapa 9B.
 *
 * Nunca cria nem envia mensagem. O único efeito permitido sobre o fluxo da 9A e
 * marcar a conversa como precisando de atendimento humano.
 */
class ConversationInterpretationService
{
    public function __construct(
        private readonly AiInterpretationGuard $guard,
        private readonly MessageClassificationService $classifier,
        private readonly InsightExtractionService $extraction,
        private readonly ConversationEventService $events,
        private readonly SystemSettingService $settings,
    ) {}

    /**
     * @return array{classification: ?ConversationMessageClassification, insight: ?ConversationInsight, blocked: ?string}
     */
    public function interpret(ConversationMessage $message): array
    {
        $state = ConversationFlowState::query()
            ->where('conversation_id', $message->conversation_id)
            ->first();

        $allowed = $this->guard->canInterpret($message, $state);

        if (! $allowed['allowed']) {
            $this->recordBlocked($message, $allowed['reason']);

            return ['classification' => null, 'insight' => null, 'blocked' => $allowed['reason']];
        }

        $classification = $this->classifier->classify($message, $state);

        $insight = $this->shouldExtract($classification, $message, $state)
            ? $this->extraction->extract($message, $state)
            : null;

        $this->applyReview($message, $state, $classification, $insight);

        return ['classification' => $classification, 'insight' => $insight, 'blocked' => null];
    }

    /**
     * Extrai quando a classificação diz que e resposta a pergunta, ou quando o
     * próprio fluxo registra que esta mensagem respondeu a pergunta sorteada.
     *
     * O estagio corrente não serve como critério: a 9A já pode ter encerrado a
     * conversa antes do job de interpretação rodar.
     */
    private function shouldExtract(
        ConversationMessageClassification $classification,
        ConversationMessage $message,
        ?ConversationFlowState $state,
    ): bool {
        if (in_array($classification->classification, [
            MessageClassification::OptOut,
            MessageClassification::PermissionNo,
            MessageClassification::MediaOrUnsupported,
            MessageClassification::InsultOrAbuse,
        ], true)) {
            return false;
        }

        if ($classification->classification->allowsExtraction()) {
            return true;
        }

        return $state !== null
            && $state->selected_question_id !== null
            && $state->last_processed_message_id === $message->id;
    }

    private function applyReview(
        ConversationMessage $message,
        ?ConversationFlowState $state,
        ConversationMessageClassification $classification,
        ?ConversationInsight $insight,
    ): void {
        $reason = $insight?->review_reason ?? $classification->review_reason;
        $needsReview = $classification->requires_human_review || ($insight?->requires_human_review ?? false);

        if (! $needsReview) {
            return;
        }

        if ($state && ! $state->needs_human_review) {
            // Apenas a marcação de revisão. O estagio continua sendo decidido
            // exclusivamente pelas regras deterministicas da 9A.
            $state->forceFill(['needs_human_review' => true])->save();
        }

        if ($message->conversation) {
            $this->events->record(
                $message->conversation,
                'ai_review_required',
                'Interpretação encaminhou a conversa para atendimento humano.',
                $message,
                null,
                [
                    'reason' => $reason,
                    'classification' => $classification->classification->value,
                    'source' => $classification->source->value,
                ],
            );
        }
    }

    private function recordBlocked(ConversationMessage $message, ?string $reason): void
    {
        if (! $message->conversation) {
            return;
        }

        $this->events->record(
            $message->conversation,
            'ai_interpretation_blocked',
            'Interpretação bloqueada.',
            $message,
            null,
            ['reason' => $reason],
        );
    }

    public function queueName(): string
    {
        return (string) $this->settings->get('ai.queue', 'ai-interpretation');
    }
}
