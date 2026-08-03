<?php

namespace App\Services\ResponseGeneration;

use App\Contracts\ConversationResponseGenerator;
use App\Enums\ConversationFlowStage;
use App\Enums\ConversationMessageDirection;
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
use App\Services\Knowledge\KnowledgeGuard;
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
            // Pergunta factual sobre a candidata tem cascata própria: base
            // aprovada, texto institucional, e so então gente.
            if ($reason === HandoffReason::FactualQuestion) {
                $comportamento = (string) $this->settings->get('ai.response.factual_behavior', 'handoff');

                // Texto fixo não passa pelo modelo: ele foi escrito por gente,
                // não cita fonte e não promete. Chamar o provedor para devolver
                // sempre a mesma frase seria gastar token e abrir espaço para
                // improviso.
                if ($comportamento === 'institutional' && $this->sendInstitutionalAnswer($state, $message)) {
                    return null;
                }

                if ($comportamento === 'knowledge' && app(KnowledgeGuard::class)->groundingEnabledForFlow($state->flow)) {
                    $reason = null;
                }
            }

            if ($reason !== null) {
                $this->handoff->handoff($state, $reason, $message);

                return null;
            }
        }

        // Limite de aprofundamentos: agradece e conclui em vez de parar calado.
        if ($state->followups_count >= $this->turnLimit($state)) {
            // Salvo quando ainda ha pergunta esperando resposta. Duas mensagens
            // seguidas produzem dois jobs, e o segundo chegava aqui depois de o
            // primeiro ja ter enviado o último aprofundamento: a pessoa recebia
            // a pergunta e o "obrigado, sua opinião foi registrada" no mesmo
            // minuto, e a resposta que ela ainda ia escrever caia no vazio.
            if ($this->questionAwaitingAnswer($message)) {
                return null;
            }

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

            // A base não tinha a resposta. Antes de chamar gente, o texto
            // institucional — que e fixo, escrito por pessoa e sempre o mesmo —
            // resolve a pergunta mais comum sem prometer nada.
            if ($reason === HandoffReason::FactualQuestion && $this->sendInstitutionalAnswer($state, $message)) {
                return;
            }

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
    /**
     * @param  bool  $safetyNet  Envio pela rede de segurança: passa pelas mesmas
     *                           condições comuns de `canSend`, mas o histórico
     *                           não pode registrar como aprovação de uma pessoa,
     *                           porque ninguém leu o texto antes de sair.
     */
    public function send(ConversationReplySuggestion $suggestion, ?User $user = null, bool $auto = false, bool $safetyNet = false): array
    {
        $check = $auto ? $this->guard->canAutoSend($suggestion) : $this->guard->canSend($suggestion);

        if (! $check['allowed']) {
            $this->refuse($suggestion, $check['reason']);

            return ['sent' => false, 'reason' => $check['reason']];
        }

        // Trava por conversa e revalidação dentro da transação: duas aprovações
        // simultaneas produzem um único envio.
        return DB::transaction(function () use ($suggestion, $user, $auto, $safetyNet): array {
            $fresh = ConversationReplySuggestion::query()
                ->whereKey($suggestion->id)
                ->lockForUpdate()
                ->first();

            if (! $fresh || $fresh->active_source_message_id === null) {
                return ['sent' => false, 'reason' => 'sugestao_ja_processada'];
            }

            $conversation = $fresh->conversation;
            // O aviso de transcrição vale para os dois caminhos de saída: a
            // pessoa que mandou áudio precisa saber que ele virou texto,
            // independentemente de quem escreveu a resposta.
            $text = $this->automated->applyTransparency(
                $fresh->flow,
                $this->automated->applyTranscriptionNotice($conversation, $fresh->outgoingText()),
            );

            $message = $this->replies->createPending(
                conversation: $conversation,
                body: $text,
                // Texto da IA que ninguém aprovou é automação, não aprovação.
                origin: $safetyNet ? ConversationMessageOrigin::Automation : ConversationMessageOrigin::ApprovedAi,
                user: $user,
                metadata: [
                    'generated_by_ai' => true,
                    'ai_run_id' => $fresh->ai_run_id,
                    'ai_prompt_version' => $fresh->prompt_version,
                    'ai_confidence' => $fresh->confidence,
                    'approved_by' => $user?->id,
                    'approved_at' => $user ? now() : null,
                ],
                eventType: match (true) {
                    $auto => 'ai_reply_auto_sent',
                    $safetyNet => 'ai_reply_safety_net_sent',
                    default => 'ai_reply_approved',
                },
                eventDescription: match (true) {
                    $auto => 'Resposta gerada enviada automaticamente.',
                    $safetyNet => 'Resposta gerada enviada pela rede de segurança.',
                    default => 'Resposta gerada aprovada e enviada.',
                },
                eventPayload: ['suggestion_id' => $fresh->id, 'edited' => $fresh->wasEdited()],
                auditAction: match (true) {
                    $auto => 'conversation_response.auto_sent',
                    $safetyNet => 'conversation_response.safety_net_sent',
                    default => 'conversation_response.approved',
                },
                auditDescription: match (true) {
                    $auto => 'Resposta gerada enviada automaticamente.',
                    $safetyNet => 'Resposta gerada enviada pela rede de segurança.',
                    default => 'Resposta gerada aprovada e enviada.',
                },
            );

            $fresh->forceFill([
                'status' => ReplySuggestionStatus::Sent,
                'active_source_message_id' => null,
                'sent_message_id' => $message->id,
                'sent_at' => now(),
                'auto_sent' => $auto || $safetyNet,
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

    /**
     * Saiu alguma pergunta depois desta mensagem?
     *
     * Se saiu, ela ainda não foi respondida, e encerrar agora fecharia a
     * conversa em cima de uma pergunta que a própria automação acabou de fazer.
     */
    private function questionAwaitingAnswer(ConversationMessage $message): bool
    {
        return ConversationMessage::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('direction', ConversationMessageDirection::Outgoing)
            ->whereIn('origin', [ConversationMessageOrigin::Automation, ConversationMessageOrigin::ApprovedAi])
            ->where('created_at', '>=', $message->created_at)
            ->where('id', '>', $message->id)
            ->exists();
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
    /**
     * Motivo de encaminhamento em qualquer mensagem do bloco.
     *
     * O agrupamento faz o job da mensagem mais nova responder por todas, e
     * antes so a mais nova era verificada. Uma pessoa escreveu "podemos marcar
     * para conversar pessoalmente?" e, um minuto depois, um elogio a candidata:
     * o pedido de conversa — classificado corretamente como `human_requested` —
     * foi engolido pelo agrupamento, e ela recebeu uma pergunta de pesquisa
     * sobre o elogio, como se ninguém tivesse lido o pedido.
     *
     * Pedido de gente não pode depender da ordem em que a pessoa digitou.
     */
    private function forcedHandoffReason(ConversationMessage $message): ?HandoffReason
    {
        foreach ($this->groupedClassifications($message) as $classification) {
            if ($classification->classification === MessageClassification::OptOut) {
                return HandoffReason::ExplicitRequest;
            }

            $reason = HandoffReason::fromClassification($classification->classification)
                ?? HandoffReason::fromReviewReason($classification->review_reason);

            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Responde a pergunta factual com o texto institucional.
     *
     * Último degrau antes do atendimento humano: a base não tinha a resposta,
     * mas existe um texto fixo aprovado por gente. Ele não e gerado, não cita
     * fonte e não promete — por isso pode sair sem revisão, ao contrário de
     * qualquer texto que o modelo escreveria para preencher a mesma lacuna.
     */
    private function sendInstitutionalAnswer(ConversationFlowState $state, ConversationMessage $message): bool
    {
        // `handoff`, que e o padrão, nunca responde: a pergunta factual e de
        // gente, e so a configuração muda isso.
        if (! in_array((string) $this->settings->get('ai.response.factual_behavior', 'handoff'), ['institutional', 'knowledge'], true)) {
            return false;
        }

        $texto = trim((string) $this->settings->get('ai.response.institutional_text', ''));

        if ($texto === '' || ! $this->modes->forFlow($state->flow)->allowsSending()) {
            return false;
        }

        $enviada = $this->automated->queue($state, $texto, 'institutional_answer_queued', [
            'source_message_id' => $message->id,
        ]);

        if (! $enviada) {
            return false;
        }

        $this->events->record(
            $state->conversation,
            'ai_institutional_answer',
            'Pergunta factual respondida com o texto institucional.',
            $message,
            null,
            ['source_message_id' => $message->id],
        );

        return true;
    }

    /**
     * A pergunta factual pode ser respondida sem gente?
     *
     * Só quando a configuração pedir e o fluxo tiver base de conhecimento
     * ativa. Sem base, responder seria o modelo inventando — e o encaminhamento
     * continua sendo o destino certo.
     */
    private function factualCanBeAnswered(ConversationMessage $message): bool
    {
        $comportamento = (string) $this->settings->get('ai.response.factual_behavior', 'handoff');

        if (! in_array($comportamento, ['knowledge', 'institutional'], true)) {
            return false;
        }

        if ($comportamento === 'institutional') {
            return trim((string) $this->settings->get('ai.response.institutional_text', '')) !== '';
        }

        $state = ConversationFlowState::query()
            ->with('flow')
            ->where('conversation_id', $message->conversation_id)
            ->first();

        return app(KnowledgeGuard::class)->groundingEnabledForFlow($state?->flow);
    }

    /**
     * Classificações das mensagens que a pessoa escreveu desde a última
     * resposta enviada — o bloco que esta geração responde de uma vez.
     *
     * @return \Illuminate\Support\Collection<int, ConversationMessageClassification>
     */
    private function groupedClassifications(ConversationMessage $message)
    {
        $ultimaSaida = ConversationMessage::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('direction', ConversationMessageDirection::Outgoing)
            ->where('id', '<', $message->id)
            ->max('id');

        $doBloco = ConversationMessage::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('direction', ConversationMessageDirection::Incoming)
            ->where('id', '<=', $message->id)
            ->when($ultimaSaida, fn ($query) => $query->where('id', '>', $ultimaSaida))
            ->pluck('id');

        return ConversationMessageClassification::query()
            ->whereIn('conversation_message_id', $doBloco)
            ->orderBy('conversation_message_id')
            ->get();
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
