<?php

namespace App\Services\ResponseGeneration;

use App\Contracts\ConversationResponseGenerator;
use App\Enums\ConversationFlowStage;
use App\Enums\ConversationMessageOrigin;
use App\Enums\HandoffReason;
use App\Enums\MessageClassification;
use App\Enums\ReplySuggestionAction;
use App\Enums\ReplySuggestionStatus;
use App\Jobs\SendApprovedReplyJob;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageClassification;
use App\Models\ConversationReplySuggestion;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ConversationAutomation\ConversationAutomatedReplyService;
use App\Services\ConversationAutomation\ConversationFlowStateMachine;
use App\Services\Conversations\ConversationEventService;
use App\Services\Conversations\ConversationReplyService;
use App\Services\SystemSettingService;
use Illuminate\Support\Facades\DB;

/**
 * Orquestrador da subetapa 9C.
 *
 * O caminho padrão termina em sugestão pendente. O autoenvio e um ramo separado
 * e explícito, condicionado a todos os guards.
 */
class ConversationSuggestionService
{
    public function __construct(
        private readonly ConversationResponseGenerator $generator,
        private readonly ResponseModeResolver $modes,
        private readonly SuggestionSendGuard $guard,
        private readonly ConversationHandoffService $handoff,
        private readonly ConversationReplyService $replies,
        private readonly ConversationAutomatedReplyService $automated,
        private readonly ConversationFlowStateMachine $machine,
        private readonly ConversationEventService $events,
        private readonly AuditLogger $audit,
        private readonly SystemSettingService $settings,
    ) {}

    /**
     * Ponto de entrada a partir da mensagem recebida.
     */
    public function handleIncoming(ConversationMessage $message): ?ConversationReplySuggestion
    {
        $state = ConversationFlowState::with(['flow', 'conversation.contact'])
            ->where('conversation_id', $message->conversation_id)
            ->first();

        if (! $state) {
            return null;
        }

        $mode = $this->modes->forFlow($state->flow);

        if (! $mode->generates()) {
            return null;
        }

        // Agrupamento: se já chegou mensagem mais nova, o job dela fará o
        // trabalho com o texto completo. Não geramos sobre um fragmento.
        if ($this->hasNewerIncoming($message)) {
            return null;
        }

        // Opt-out, pausa ou encerramento invalidam qualquer sugestão viva: nada
        // pendente pode sobreviver a uma decisão de parar.
        if ($state->is_paused || $state->current_stage->isTerminal()) {
            $this->handoff->invalidateLiveSuggestions(
                $state,
                $state->current_stage === ConversationFlowStage::OptedOut ? 'opt_out' : 'fluxo_encerrado',
            );

            return null;
        }

        // Categorias que nunca recebem resposta gerada: encaminha e para.
        if ($reason = $this->forcedHandoffReason($message)) {
            $this->handoff->handoff($state, $reason, $message);

            return null;
        }

        // Limite de aprofundamentos: agradece e conclui em vez de parar calado.
        if ($state->followups_count >= $this->turnLimit($state)) {
            $this->completeWithThanks($state, $message);

            return null;
        }

        $suggestion = $this->generator->generate($message, $state);

        if (! $suggestion) {
            return null;
        }

        $this->afterGeneration($state, $suggestion, $message);

        return $suggestion->refresh();
    }

    /**
     * Decide o destino da sugestão recem-criada.
     */
    private function afterGeneration(ConversationFlowState $state, ConversationReplySuggestion $suggestion, ConversationMessage $message): void
    {
        $state->forceFill(['last_suggestion_at' => now()])->save();

        $this->events->record(
            $state->conversation,
            'ai_suggestion_created',
            'Sugestão de resposta gerada.',
            $message,
            null,
            [
                'suggestion_id' => $suggestion->id,
                'action' => $suggestion->action->value,
                'status' => $suggestion->status->value,
                'mode' => $suggestion->mode->value,
            ],
        );

        // Ação de encerramento decidida pelo modelo, ainda sob aprovação humana
        // quando o modo exigir.
        if ($suggestion->action === ReplySuggestionAction::OptOut) {
            $this->handoff->handoff($state, HandoffReason::ExplicitRequest, $message);

            return;
        }

        if ($suggestion->action === ReplySuggestionAction::HandoffHuman || $suggestion->status === ReplySuggestionStatus::Blocked) {
            $reason = $suggestion->handoff_reason ?? HandoffReason::ContextConflict;
            $this->handoff->handoff($state, $reason, $message);

            return;
        }

        $auto = $this->guard->canAutoSend($suggestion);

        // O motivo da decisão de autoenvio e sempre registrado, permita ou não.
        $this->events->record(
            $state->conversation,
            'ai_auto_send_decision',
            $auto['allowed'] ? 'Autoenvio permitido.' : 'Autoenvio recusado.',
            $message,
            null,
            ['suggestion_id' => $suggestion->id, 'allowed' => $auto['allowed'], 'reason' => $auto['reason']],
        );

        if ($auto['allowed']) {
            $this->send($suggestion, null, true);
        }
    }

    /**
     * Envia a sugestão. Usado pela aprovação humana e pelo autoenvio.
     *
     * @return array{sent: bool, reason: ?string}
     */
    public function send(ConversationReplySuggestion $suggestion, ?User $user = null, bool $auto = false): array
    {
        $check = $auto ? $this->guard->canAutoSend($suggestion) : $this->guard->canSend($suggestion);

        if (! $check['allowed']) {
            $this->refuse($suggestion, $check['reason']);

            return ['sent' => false, 'reason' => $check['reason']];
        }

        // Trava por conversa e revalidação dentro da transação: duas aprovações
        // simultaneas produzem um único envio.
        return DB::transaction(function () use ($suggestion, $user, $auto): array {
            $fresh = ConversationReplySuggestion::query()
                ->whereKey($suggestion->id)
                ->lockForUpdate()
                ->first();

            if (! $fresh || $fresh->active_source_message_id === null) {
                return ['sent' => false, 'reason' => 'sugestao_ja_processada'];
            }

            $conversation = $fresh->conversation;
            $text = $this->automated->applyTransparency($fresh->flow, $fresh->outgoingText());

            $message = $this->replies->createPending(
                conversation: $conversation,
                body: $text,
                origin: ConversationMessageOrigin::ApprovedAi,
                user: $user,
                metadata: [
                    'generated_by_ai' => true,
                    'ai_run_id' => $fresh->ai_run_id,
                    'ai_prompt_version' => $fresh->prompt_version,
                    'ai_confidence' => $fresh->confidence,
                    'approved_by' => $user?->id,
                    'approved_at' => $user ? now() : null,
                ],
                eventType: $auto ? 'ai_reply_auto_sent' : 'ai_reply_approved',
                eventDescription: $auto ? 'Resposta gerada enviada automaticamente.' : 'Resposta gerada aprovada e enviada.',
                eventPayload: ['suggestion_id' => $fresh->id, 'edited' => $fresh->wasEdited()],
                auditAction: $auto ? 'conversation_response.auto_sent' : 'conversation_response.approved',
                auditDescription: $auto ? 'Resposta gerada enviada automaticamente.' : 'Resposta gerada aprovada e enviada.',
            );

            $fresh->forceFill([
                'status' => ReplySuggestionStatus::Sent,
                'active_source_message_id' => null,
                'sent_message_id' => $message->id,
                'sent_at' => now(),
                'auto_sent' => $auto,
                'approved_by' => $user?->id ?? $fresh->approved_by,
                'approved_at' => $fresh->approved_at ?? ($user ? now() : null),
            ])->save();

            $state = $fresh->state;

            if ($state && $fresh->action->isDeepening()) {
                // Contagem idempotente: incrementa apenas no envio confirmado.
                $state->forceFill(['followups_count' => $state->followups_count + 1])->save();
            }

            SendApprovedReplyJob::dispatch($message->id);

            return ['sent' => true, 'reason' => null];
        });
    }

    /**
     * Registra a recusa e tira a sugestão de circulação quando definitiva.
     */
    private function refuse(ConversationReplySuggestion $suggestion, ?string $reason): void
    {
        $status = $this->guard->statusForRefusal((string) $reason);

        $suggestion->forceFill([
            'status' => $status,
            'active_source_message_id' => null,
            'blocked_reason' => $reason,
        ])->save();

        if ($suggestion->conversation) {
            $this->events->record(
                $suggestion->conversation,
                'ai_reply_refused',
                'Envio de sugestão recusado.',
                $suggestion->sourceMessage,
                null,
                ['suggestion_id' => $suggestion->id, 'reason' => $reason],
            );
        }

        $this->audit->log('conversation_response.send_refused', 'Envio de sugestão recusado.', $suggestion, null, [
            'conversation_id' => $suggestion->conversation_id,
            'reason' => $reason,
        ]);
    }

    /**
     * Agradece e encerra quando o limite de aprofundamentos foi atingido.
     */
    private function completeWithThanks(ConversationFlowState $state, ConversationMessage $message): void
    {
        $text = $state->flow?->thank_you_text
            ?: (string) $this->settings->get('conversation_automation.thank_you_text', '');

        if ($text !== '') {
            $this->automated->queue($state, $text, 'automated_thank_you_queued', ['reason' => 'limite_de_aprofundamentos']);
        }

        $this->machine->finish(
            $state,
            ConversationFlowStage::Completed,
            'limite_de_aprofundamentos',
            'followup_limit_reached',
            $message,
        );
    }

    private function turnLimit(ConversationFlowState $state): int
    {
        $global = max(0, (int) $this->settings->get('ai.response.max_followups', 2));
        $flow = $state->flow?->max_followups;

        return $flow !== null && $flow > 0 ? min($global, (int) $flow) : $global;
    }

    /**
     * Categorias e sinalizações que nunca recebem resposta gerada.
     */
    private function forcedHandoffReason(ConversationMessage $message): ?HandoffReason
    {
        $classification = ConversationMessageClassification::query()
            ->where('conversation_message_id', $message->id)
            ->latest('id')
            ->first();

        if (! $classification) {
            return null;
        }

        if ($classification->classification === MessageClassification::OptOut) {
            return HandoffReason::ExplicitRequest;
        }

        return HandoffReason::fromClassification($classification->classification)
            ?? HandoffReason::fromReviewReason($classification->review_reason);
    }

    private function hasNewerIncoming(ConversationMessage $message): bool
    {
        return ConversationMessage::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('direction', 'incoming')
            ->where('id', '>', $message->id)
            ->exists();
    }
}
