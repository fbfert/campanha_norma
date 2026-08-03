<?php

namespace App\Services\ResponseGeneration;

use App\Contracts\ConversationResponseGenerator;
use App\Enums\ReplySuggestionStatus;
use App\Enums\SuggestionFeedback;
use App\Models\ConversationReplySuggestion;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * Ações humanas sobre uma sugestão.
 *
 * Toda ação e individual: não existe caminho que aprove mais de uma sugestão em
 * uma única operação.
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

        // Obsolescência verificada antes de qualquer escrita.
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

        $this->audit->log('conversation_response.approved_by_user', 'Sugestão aprovada por operador.', $suggestion, null, [
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

        $this->audit->log('conversation_response.rejected', 'Sugestão rejeitada.', $suggestion, null, [
            'conversation_id' => $suggestion->conversation_id,
            'suggestion_id' => $suggestion->id,
        ], $user);
    }

    /**
     * Aprova e envia todas as pendentes válidas.
     *
     * A ausência disto era deliberada, e esta anotada em
     * `docs/ai-response-generation.md`: uma tela que aprova cinquenta sugestões
     * com um clique transforma revisão humana em carimbo. A decisão de ter o
     * botão foi tomada por quem opera a campanha, depois de a objeção ter sido
     * apresentada. O que o código pode garantir e que ele não vire uma porta
     * lateral.
     *
     * Por isso cada sugestão passa por `approveAndSend`, individualmente, com
     * todos os guards: obsolescência, validador de texto, elegibilidade do
     * contato, janela de horário e fundamentação. O que estava barrado continua
     * barrado — o botão poupa cliques, não verificações.
     *
     * Obsoletas ficam de fora: elas seriam recusadas de qualquer forma, e
     * inclui-las encheria o relatório de falhas previsíveis.
     *
     * @return array{enviadas: int, recusadas: int, motivos: array<string, int>}
     */
    public function approveAllPending(User $user): array
    {
        $resultado = ['enviadas' => 0, 'recusadas' => 0, 'motivos' => []];

        $pendentes = ConversationReplySuggestion::query()
            ->where('status', ReplySuggestionStatus::Pending)
            ->orderBy('id')
            ->get()
            ->reject(fn (ConversationReplySuggestion $sugestao): bool => $sugestao->isStale());

        foreach ($pendentes as $sugestao) {
            $envio = $this->approveAndSend($sugestao, $user);

            if ($envio['sent']) {
                $resultado['enviadas']++;

                continue;
            }

            $resultado['recusadas']++;
            $motivo = $envio['reason'] ?? 'desconhecido';
            $resultado['motivos'][$motivo] = ($resultado['motivos'][$motivo] ?? 0) + 1;
        }

        if ($resultado['enviadas'] > 0 || $resultado['recusadas'] > 0) {
            $this->audit->log('conversation_response.bulk_approved', 'Aprovação em massa de sugestões pendentes.', null, null, $resultado, $user);
        }

        return $resultado;
    }

    /**
     * Tira de circulação as sugestões que perderam a validade.
     *
     * Uma sugestão fica obsoleta quando a pessoa escreve de novo: o texto
     * responde a uma mensagem que já não e a última, e aprova-lo mandaria uma
     * resposta fora de hora. O envio já recusa essas, então elas so ocupam
     * espaço — e fila cheia de item morto e o que faz alguém parar de ler a
     * fila.
     *
     * Não e aprovação em massa nem rejeição: nada e enviado, e o motivo fica
     * gravado como obsolescência, que e o que de fato aconteceu.
     *
     * @return int quantas saíram de circulação
     */
    public function discardStale(User $user): int
    {
        $descartadas = 0;

        ConversationReplySuggestion::query()
            ->where('status', ReplySuggestionStatus::Pending)
            ->orderBy('id')
            ->chunkById(200, function ($sugestoes) use ($user, &$descartadas): void {
                foreach ($sugestoes as $sugestao) {
                    if (! $sugestao->isStale()) {
                        continue;
                    }

                    $sugestao->forceFill([
                        'status' => ReplySuggestionStatus::Superseded,
                        'active_source_message_id' => null,
                        'blocked_reason' => 'sugestao_obsoleta',
                    ])->save();

                    $descartadas++;
                }
            });

        if ($descartadas > 0) {
            $this->audit->log('conversation_response.stale_discarded', 'Sugestões obsoletas descartadas.', null, null, [
                'total' => $descartadas,
            ], $user);
        }

        return $descartadas;
    }

    /**
     * Regenera com justificativa. A sugestão anterior sai de circulação mas
     * continua legível como histórico.
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

        $this->audit->log('conversation_response.regenerated', 'Sugestão regenerada.', $suggestion, null, [
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
     * Assume manualmente: pausa a automação e tira a sugestão de circulação.
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
        $this->audit->log('conversation_response.feedback', 'Feedback registrado para sugestão.', $suggestion, null, [
            'conversation_id' => $suggestion->conversation_id,
            'suggestion_id' => $suggestion->id,
            'feedback' => $feedback->value,
        ], $user);
    }
}
