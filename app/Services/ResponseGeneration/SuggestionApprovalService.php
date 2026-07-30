<?php

namespace App\Services\ResponseGeneration;

use App\Contracts\ConversationResponseGenerator;
use App\Enums\ReplySuggestionStatus;
use App\Enums\SuggestionFeedback;
use App\Models\ConversationReplySuggestion;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * Acoes humanas sobre uma sugestao.
 *
 * Toda acao e individual: nao existe caminho que aprove mais de uma sugestao em
 * uma unica operacao.
 */
class SuggestionApprovalService
{
    public function __construct(
        private readonly ConversationSuggestionService $suggestions,
        private readonly ConversationResponseGenerator $generator,
        private readonly ConversationHandoffService $handoff,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Aprova e envia. O texto editado vai para `final_text`, preservando o gerado.
     *
     * @return array{sent: bool, reason: ?string}
     */
    public function approveAndSend(ConversationReplySuggestion $suggestion, User $user, ?string $editedText = null): array
    {
        if (! $suggestion->status->isLive()) {
            return ['sent' => false, 'reason' => 'sugestao_nao_esta_viva'];
        }

        // Obsolescencia verificada antes de qualquer escrita.
        if ($suggestion->isStale()) {
            $suggestion->forceFill([
                'status' => ReplySuggestionStatus::Superseded,
                'active_source_message_id' => null,
                'blocked_reason' => 'sugestao_obsoleta',
            ])->save();

            return ['sent' => false, 'reason' => 'sugestao_obsoleta'];
        }

        $edited = $editedText !== null ? trim($editedText) : null;

        $suggestion->forceFill([
            'final_text' => $edited !== null && $edited !== '' ? $edited : $suggestion->generated_text,
            'status' => ReplySuggestionStatus::Approved,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ])->save();

        $this->audit->log('conversation_response.approved_by_user', 'Sugestao aprovada por operador.', $suggestion, null, [
            'conversation_id' => $suggestion->conversation_id,
            'suggestion_id' => $suggestion->id,
            'edited' => $suggestion->wasEdited(),
        ], $user);

        return $this->suggestions->send($suggestion->refresh(), $user);
    }

    public function reject(ConversationReplySuggestion $suggestion, User $user, ?string $reason = null): void
    {
        $suggestion->forceFill([
            'status' => ReplySuggestionStatus::Rejected,
            'active_source_message_id' => null,
            'rejected_by' => $user->id,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ])->save();

        $this->audit->log('conversation_response.rejected', 'Sugestao rejeitada.', $suggestion, null, [
            'conversation_id' => $suggestion->conversation_id,
            'suggestion_id' => $suggestion->id,
        ], $user);
    }

    /**
     * Regenera com justificativa. A sugestao anterior sai de circulacao mas
     * continua legivel como historico.
     */
    public function regenerate(ConversationReplySuggestion $suggestion, User $user, string $justification): ?ConversationReplySuggestion
    {
        $state = $suggestion->state;
        $message = $suggestion->sourceMessage;

        if (! $state || ! $message) {
            return null;
        }

        $suggestion->forceFill([
            'status' => ReplySuggestionStatus::Superseded,
            'active_source_message_id' => null,
            'blocked_reason' => 'regenerada',
            'regeneration_reason' => $justification,
        ])->save();

        $this->audit->log('conversation_response.regenerated', 'Sugestao regenerada.', $suggestion, null, [
            'conversation_id' => $suggestion->conversation_id,
            'suggestion_id' => $suggestion->id,
            'justification' => $justification,
        ], $user);

        return $this->generator->generate($message, $state, [
            'attempt' => $suggestion->generation_attempt + 1,
            'regeneration_reason' => $justification,
        ]);
    }

    /**
     * Assume manualmente: pausa a automacao e tira a sugestao de circulacao.
     */
    public function takeOver(ConversationReplySuggestion $suggestion, User $user): void
    {
        $state = $suggestion->state;

        if ($state) {
            $state->forceFill(['is_paused' => true, 'needs_human_review' => true])->save();
            $this->handoff->invalidateLiveSuggestions($state, 'assumido_manualmente');
        }

        $this->audit->log('conversation_response.taken_over', 'Conversa assumida manualmente.', $suggestion, null, [
            'conversation_id' => $suggestion->conversation_id,
            'suggestion_id' => $suggestion->id,
        ], $user);
    }

    public function feedback(ConversationReplySuggestion $suggestion, User $user, SuggestionFeedback $feedback, ?string $reason = null): void
    {
        $suggestion->forceFill([
            'feedback' => $feedback,
            'feedback_reason' => $reason,
            'feedback_by' => $user->id,
            'feedback_at' => now(),
        ])->save();

        // Registro apenas. Nenhum prompt, modelo, threshold ou allowlist muda
        // por causa deste valor.
        $this->audit->log('conversation_response.feedback', 'Feedback registrado para sugestao.', $suggestion, null, [
            'conversation_id' => $suggestion->conversation_id,
            'suggestion_id' => $suggestion->id,
            'feedback' => $feedback->value,
        ], $user);
    }
}
