<?php

namespace App\Services\ConversationAutomation;

use App\Enums\ContactStatus;
use App\Enums\ConversationFlowStage;
use App\Enums\PermissionResponseClassification;
use App\Events\ConversationMessageEvaluated;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Contacts\ContactDataService;
use App\Services\Conversations\ConversationEventService;
use App\Services\Conversations\ReplyInterruptionService;
use App\Services\ResponseGeneration\ResponseModeResolver;
use App\Services\SystemSettingService;
use Throwable;

/**
 * Orquestrador do fluxo conversacional determinístico da subetapa 9A.
 */
class ConversationFlowService
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly ConversationAutomationGuard $guard,
        private readonly PermissionResponseClassifier $classifier,
        private readonly ConversationQuestionSelector $selector,
        private readonly ConversationFlowStateMachine $machine,
        private readonly ConversationAutomatedReplyService $replies,
        private readonly ConversationEventService $events,
        private readonly ReplyInterruptionService $interruption,
        private readonly ContactDataService $contacts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Ativa o fluxo após o envio da mensagem inicial de campanha.
     * Idempotente: se já existe estado para a conversa, não recria.
     */
    public function activateForConversation(Conversation $conversation, ConversationFlow $flow): ?ConversationFlowState
    {
        $contact = $conversation->contact;

        if (! $this->contactEligible($contact)) {
            return null;
        }

        $existing = ConversationFlowState::where('conversation_id', $conversation->id)->first();
        if ($existing) {
            return $existing;
        }

        $state = ConversationFlowState::create([
            'conversation_id' => $conversation->id,
            'conversation_flow_id' => $flow->id,
            'current_stage' => ConversationFlowStage::Inactive,
            'automated_messages_count' => 1,
            'attempts_count' => 0,
            'started_at' => now(),
            'expires_at' => now()->addHours(max(1, (int) $flow->validity_hours)),
        ]);

        $this->machine->transition($state, ConversationFlowStage::InitialMessageSent, 'campaign_initial_message_sent');
        $this->machine->transition($state, ConversationFlowStage::WaitingPermission, 'awaiting_permission');

        $this->audit->log('conversation_automation.activated', 'Fluxo conversacional ativado por campanha.', $state, null, [
            'conversation_id' => $conversation->id,
            'flow_id' => $flow->id,
        ]);

        return $state->refresh();
    }

    /**
     * Avalia uma mensagem recebida. Idempotente por `last_processed_message_id`.
     */
    public function handleIncomingMessage(ConversationMessage $message): void
    {
        $state = ConversationFlowState::with(['flow', 'conversation.contact'])
            ->where('conversation_id', $message->conversation_id)
            ->first();

        if (! $state) {
            return;
        }

        // Idempotência: mensagem já processada não produz efeito novo.
        if ($state->last_processed_message_id !== null && $state->last_processed_message_id >= $message->id) {
            return;
        }

        $blockedReason = $this->runDeterministicFlow($state, $message);

        // Ponto de extensão: disparado depois que as regras deterministicas já
        // decidiram, inclusive quando o motor esta desligado. A 9A não conhece
        // nenhum ouvinte e não depende de nenhum.
        $this->notifyEvaluated($message, $state->refresh(), $blockedReason);
    }

    /**
     * Executa o motor determinístico. Devolve o motivo do bloqueio, quando houver.
     */
    private function runDeterministicFlow(ConversationFlowState $state, ConversationMessage $message): ?string
    {
        $evaluate = $this->guard->canEvaluate($state);
        if (! $evaluate['allowed']) {
            $this->recordBlocked($state, $message, $evaluate['reason']);

            return $evaluate['reason'];
        }

        // Mensagem fora de ordem não reinicia fluxo já encerrado.
        if ($state->current_stage->isTerminal()) {
            $this->recordBlocked($state, $message, 'fluxo_encerrado');

            return 'fluxo_encerrado';
        }

        $state->forceFill([
            'last_processed_message_id' => $message->id,
            'attempts_count' => $state->attempts_count + 1,
        ])->save();

        /*
         | Aluno que confunde a campanha com assunto da escola.
         |
         | O polo Rainbow fala com essas pessoas por outro motivo — mensalidade,
         | matrícula, boleto — e do lado delas é a mesma pessoa escrevendo. Uma
         | contatada respondeu ao convite com "acho que deve ser sobre as
         | mensalidades atrasadas né? segunda-feira eu pago", constrangida,
         | prometendo pagar.
         |
         | Encaminhar para gente estava certo e era lento demais: cada minuto de
         | silêncio confirmava a leitura dela. Desfazer o mal-entendido não exige
         | julgamento humano nenhum — exige dizer que não é sobre isso, na mesma
         | mensagem que faz a pergunta, para não prolongar o constrangimento.
         */
        if ($this->isSchoolMatter($message)) {
            $this->clarifyAndAsk($state, $message);

            return null;
        }

        match ($state->current_stage) {
            ConversationFlowStage::WaitingPermission => $this->handlePermissionReply($state, $message),
            ConversationFlowStage::WaitingAnswer, ConversationFlowStage::QuestionSelected => $this->handleAnswer($state, $message),
            default => $this->recordBlocked($state, $message, 'estagio_sem_acao'),
        };

        return null;
    }

    /**
     * Uma falha em um ouvinte jamais invalida o processamento determinístico
     * que já ocorreu.
     */
    private function notifyEvaluated(ConversationMessage $message, ConversationFlowState $state, ?string $blockedReason): void
    {
        try {
            ConversationMessageEvaluated::dispatch($message, $state, $blockedReason === null, $blockedReason);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function handlePermissionReply(ConversationFlowState $state, ConversationMessage $message): void
    {
        $result = $this->classifier->classify($message->body);
        $classification = $result['classification'];

        $metadata = [
            'reason' => $result['reason'],
            'matched' => $result['matched'],
        ];

        match ($classification) {
            PermissionResponseClassification::OptOut => $this->applyOptOut($state, $message, $metadata),
            PermissionResponseClassification::PermissionNo => $this->applyRefusal($state, $message, $metadata),
            PermissionResponseClassification::PermissionYes => $this->applyGranted($state, $message, $metadata),
            PermissionResponseClassification::Ambiguous => $this->applyAmbiguous($state, $message, $metadata),
        };
    }

    private function applyGranted(ConversationFlowState $state, ConversationMessage $message, array $metadata): void
    {
        $this->machine->transition(
            $state,
            ConversationFlowStage::PermissionGranted,
            'permission_classified',
            $message,
            PermissionResponseClassification::PermissionYes->value,
            null,
            $metadata,
        );

        $send = $this->guard->canSend($state);
        if (! $send['allowed']) {
            $this->recordBlocked($state, $message, $send['reason']);

            return;
        }

        $this->sendNextQuestion($state, $message);
    }

    /**
     * Sorteia a próxima pergunta ainda não usada e a envia.
     *
     * Usado tanto na primeira pergunta, logo após a autorização, quanto nas
     * seguintes, quando o fluxo pede mais de uma pergunta por conversa.
     */
    private function sendNextQuestion(ConversationFlowState $state, ConversationMessage $message): void
    {
        $usage = $this->selector->select($state);

        if (! $usage) {
            $this->applyNoQuestionAvailable($state, $message);

            return;
        }

        $state->forceFill([
            'selected_question_id' => $usage->conversation_flow_question_id,
            'selected_question_snapshot' => $usage->question_snapshot,
        ])->save();

        $this->machine->transition($state, ConversationFlowStage::QuestionSelected, 'question_selected', $message, null, null, [
            'question_id' => $usage->conversation_flow_question_id,
        ]);

        $automated = $this->replies->queue($state, $usage->question_snapshot, 'automated_question_queued', [
            'question_id' => $usage->conversation_flow_question_id,
        ]);

        if (! $automated) {
            $this->machine->markForHuman($state, 'automated_message_not_created', $message);

            return;
        }

        $usage->forceFill([
            'sent_at' => now(),
            'conversation_message_id' => $automated->id,
            'result' => 'queued',
        ])->save();

        $this->machine->transition($state, ConversationFlowStage::WaitingAnswer, 'question_sent', $automated, null, null, [
            'question_id' => $usage->conversation_flow_question_id,
        ]);
    }

    /**
     * A pessoa entendeu a abordagem como assunto da escola?
     *
     * A lista é deliberada e fica em configuração, como as de consentimento: o
     * vocabulário do polo muda com o tempo, e quem opera precisa poder ajustar
     * sem mexer em código.
     */
    private function isSchoolMatter(ConversationMessage $message): bool
    {
        $termos = collect(explode('|', (string) $this->settings->get('conversation_automation.school_matter_expressions', '')))
            ->map(fn (string $termo): string => trim(mb_strtolower($termo)))
            ->filter();

        if ($termos->isEmpty()) {
            return false;
        }

        $texto = mb_strtolower((string) $message->body);

        return $termos->contains(fn (string $termo): bool => str_contains($texto, $termo));
    }

    /**
     * Diz que não é sobre a escola e faz a pergunta, na mesma mensagem.
     *
     * Separar as duas prolongaria o constrangimento: a pessoa leria o
     * esclarecimento e ficaria esperando o que viria depois. Uma pessoa não
     * faria isso em duas mensagens.
     */
    private function clarifyAndAsk(ConversationFlowState $state, ConversationMessage $message): void
    {
        $aviso = trim((string) $this->settings->get('conversation_automation.school_matter_reply', ''));

        if ($aviso === '' || ! $this->guard->canSend($state)['allowed']) {
            $this->recordBlocked($state, $message, 'assunto_da_escola_sem_texto');

            return;
        }

        $pergunta = trim((string) $state->selected_question_snapshot);

        // Sem pergunta sorteada — a pessoa nem chegou a consentir — o motor
        // escolhe uma agora: o esclarecimento sozinho deixaria a conversa
        // parada, e ela já demonstrou que está disposta a responder.
        if ($pergunta === '') {
            $this->events->record($state->conversation, 'automation_school_matter_clarified', 'Assunto da escola esclarecido; seguindo com a pergunta.', $message);
            $this->replies->queue($state, $aviso, 'automation_school_matter_clarified');
            $this->sendNextQuestion($state, $message);

            return;
        }

        $this->replies->queue($state, trim($aviso.' '.$pergunta), 'automation_school_matter_clarified');

        $this->events->record($state->conversation, 'automation_school_matter_clarified', 'Assunto da escola esclarecido; pergunta refeita junto.', $message);
    }

    /**
     * A mensagem é consentimento atrasado, e não a resposta à pergunta?
     *
     * Quem responde ao convite enquanto a pergunta já está a caminho manda algo
     * que não responde nada — "pode sim", "tudo bem", "claro". As duas mensagens
     * se cruzam, e o fluxo contava aquilo como a resposta: a pergunta ficava
     * sem ser respondida para sempre, e a conversa seguia para o aprofundamento
     * sem ter o que aprofundar.
     *
     * Não é caso raro. Em 43% das mensagens que chegam em até quinze segundos
     * das nossas é exatamente isso.
     *
     * A pergunta é refeita **uma vez só**. Sem teto, alguém que responda "sim"
     * de novo receberia a pergunta de novo, indefinidamente — e uma pergunta
     * fechada, se algum fluxo tiver uma, seria respondida com "sim" de verdade.
     * Refazer uma vez custa uma mensagem; repetir sem parar custa a conversa.
     */
    private function isLateConsent(ConversationFlowState $state, PermissionResponseClassification $classification): bool
    {
        return $classification === PermissionResponseClassification::PermissionYes
            && $state->question_reasked_at === null;
    }

    /**
     * Refaz a pergunta, dizendo que é a mesma de antes.
     *
     * Reenviar o texto idêntico pareceria defeito para quem está do outro lado.
     * A frase de retomada deixa claro que não é mensagem nova: é a mesma
     * pergunta, que cruzou com a dela.
     */
    private function reaskQuestion(ConversationFlowState $state, ConversationMessage $message): void
    {
        $pergunta = trim((string) $state->selected_question_snapshot);

        if ($pergunta === '' || ! $this->guard->canSend($state)['allowed']) {
            return;
        }

        $prefixo = trim((string) $this->settings->get('conversation_automation.reask_prefix', 'Sobre o que te perguntei:'));

        $state->forceFill(['question_reasked_at' => now()])->save();

        $this->replies->queue($state, trim($prefixo.' '.$pergunta), 'automation_question_reasked');

        $this->events->record($state->conversation, 'automation_question_reasked', 'Pergunta refeita: a resposta anterior era consentimento atrasado.', $message, null, [
            'question_id' => $state->selected_question_id,
        ]);
    }

    private function applyRefusal(ConversationFlowState $state, ConversationMessage $message, array $metadata): void
    {
        $shouldBlock = (bool) $this->settings->get('conversation_automation.mark_do_not_contact_on_refusal', '0');

        if ($shouldBlock && $state->conversation?->contact) {
            $this->contacts->setDoNotContact($state->conversation->contact, true, 'Recusou participar da pesquisa conversacional.');
        }

        $text = $state->flow?->permission_denied_text ?: (string) $this->settings->get('conversation_automation.permission_denied_text', '');
        if ($text !== '' && $this->guard->canSend($state)['allowed']) {
            $this->replies->queue($state, $text, 'automated_refusal_ack_queued');
        }

        $this->machine->finish(
            $state,
            ConversationFlowStage::PermissionDenied,
            'permission_denied',
            'permission_classified',
            $message,
            PermissionResponseClassification::PermissionNo->value,
            null,
            $metadata,
        );
    }

    private function applyOptOut(ConversationFlowState $state, ConversationMessage $message, array $metadata): void
    {
        $contact = $state->conversation?->contact;

        if ($contact) {
            $this->contacts->setDoNotContact($contact, true, 'Pedido de não contatar recebido na pesquisa conversacional.');
            $this->interruption->interrupt($contact, $contact->phone_normalized);
        }

        $state->forceFill(['is_paused' => true])->save();

        $this->machine->finish(
            $state,
            ConversationFlowStage::OptedOut,
            'opt_out',
            'permission_classified',
            $message,
            PermissionResponseClassification::OptOut->value,
            null,
            $metadata,
        );

        $this->audit->log('conversation_automation.opt_out', 'Opt-out registrado pela pesquisa conversacional.', $state, null, [
            'conversation_id' => $state->conversation_id,
            'contact_id' => $contact?->id,
        ]);
    }

    private function applyAmbiguous(ConversationFlowState $state, ConversationMessage $message, array $metadata): void
    {
        $behavior = (string) $this->settings->get('conversation_automation.ambiguous_behavior', 'waiting_human');

        if ($behavior === 'keep_waiting') {
            $this->events->record($state->conversation, 'automation_ambiguous_reply', 'Resposta ambígua mantida em espera.', $message, null, $metadata);

            return;
        }

        $this->machine->markForHuman($state, 'permission_classified', $message, PermissionResponseClassification::Ambiguous->value);
        $this->events->record($state->conversation, 'automation_waiting_human', 'Resposta ambígua encaminhada para atendimento humano.', $message, null, $metadata);
    }

    private function applyNoQuestionAvailable(ConversationFlowState $state, ConversationMessage $message): void
    {
        $behavior = (string) $this->settings->get('conversation_automation.no_question_behavior', 'waiting_human');

        if ($behavior === 'completed') {
            $this->machine->finish($state, ConversationFlowStage::Completed, 'sem_pergunta_disponivel', 'no_question_available', $message);
        } else {
            $state->forceFill(['end_reason' => $state->end_reason ?? 'sem_pergunta_disponivel'])->save();
            $this->machine->markForHuman($state, 'no_question_available', $message);
        }

        $this->events->record($state->conversation, 'automation_no_question_available', 'Nenhuma pergunta ativa disponível para esta conversa.', $message);
        $this->audit->log('conversation_automation.no_question_available', 'Nenhuma pergunta ativa disponível.', $state, null, [
            'conversation_id' => $state->conversation_id,
            'flow_id' => $state->conversation_flow_id,
        ]);
    }

    /**
     * Resposta a pergunta principal.
     *
     * Sem aprofundamento configurado, a 9A agradece e encerra — comportamento
     * original desta etapa.
     *
     * Com aprofundamento, ela para em `answer_received` e entrega a conversa
     * para a 9C. Agradecer aqui encerraria o fluxo no mesmo instante, e
     * `completed` e terminal: toda pergunta gerada a partir da resposta seria
     * recusada depois com `fluxo_encerrado`. Quem agradece e encerra passa a
     * ser a 9C, pela ação `thank_and_complete`, quando os turnos acabarem.
     */
    private function handleAnswer(ConversationFlowState $state, ConversationMessage $message): void
    {
        $result = $this->classifier->classify($message->body);

        if ($result['classification'] === PermissionResponseClassification::OptOut) {
            $this->applyOptOut($state, $message, ['reason' => $result['reason'], 'matched' => $result['matched']]);

            return;
        }

        if ($this->isLateConsent($state, $result['classification'])) {
            $this->reaskQuestion($state, $message);

            return;
        }

        $this->machine->transition($state, ConversationFlowStage::AnswerReceived, 'answer_received', $message);

        $state->questionUsages()
            ->where('conversation_flow_question_id', $state->selected_question_id)
            ->update(['result' => 'answered']);

        // Pesquisa com mais de uma pergunta continua pelas próprias perguntas,
        // sorteadas e sem repetir. Isso vem antes do aprofundamento por IA: a
        // pergunta cadastrada e igual para todo mundo e produz resposta
        // comparável, que e o ponto de uma pesquisa.
        if ($this->hasMoreMainQuestions($state)) {
            $this->sendNextQuestion($state, $message);

            return;
        }

        if ($this->deepeningTakesOver($state)) {
            $this->events->record($state->conversation, 'automation_deepening_handover', 'Resposta recebida; aprofundamento a cargo da geração de respostas.', $message, null, [
                'max_followups' => (int) $state->flow?->max_followups,
            ]);

            return;
        }

        $text = $state->flow?->thank_you_text ?: (string) $this->settings->get('conversation_automation.thank_you_text', '');
        if ($text !== '' && $this->guard->canSend($state)['allowed']) {
            $this->replies->queue($state, $text, 'automated_thank_you_queued');
        }

        $this->machine->finish($state, ConversationFlowStage::Completed, 'resposta_recebida', 'flow_completed', $message);
    }

    /**
     * Ainda ha pergunta da pesquisa a fazer nesta conversa?
     *
     * O teto e `max_main_questions` do fluxo. Conta perguntas efetivamente
     * enviadas, não respondidas: quem não responde a terceira não deve receber
     * a quarta por causa de uma contagem que ignora o silêncio.
     */
    private function hasMoreMainQuestions(ConversationFlowState $state): bool
    {
        $limite = (int) ($state->flow?->max_main_questions ?? 1);

        if ($limite < 2) {
            return false;
        }

        $enviadas = $state->questionUsages()->whereNotNull('sent_at')->count();

        if ($enviadas >= $limite) {
            return false;
        }

        // Sem pergunta nova disponível não ha o que continuar: cair aqui
        // levaria a conversa para `waiting_human` sem motivo, quando o certo
        // e agradecer e encerrar como qualquer pesquisa concluída.
        return $state->flow?->questions()
            ->where('is_active', true)
            ->whereNotIn('id', $state->questionUsages()->pluck('conversation_flow_question_id'))
            ->exists() ?? false;
    }

    /**
     * A 9C assume a conversa depois da resposta?
     *
     * Exige as duas pontas: aprofundamento configurado no fluxo e geração
     * efetivamente ligada. Sem a segunda, o fluxo ficaria parado em
     * `answer_received` esperando uma etapa que não vai rodar — e a pessoa
     * ficaria sem nem o agradecimento.
     */
    private function deepeningTakesOver(ConversationFlowState $state): bool
    {
        $flow = $state->flow;

        if (! $flow || (int) $flow->max_followups < 1) {
            return false;
        }

        return app(ResponseModeResolver::class)->forFlow($flow)->generates();
    }

    private function recordBlocked(ConversationFlowState $state, ?ConversationMessage $message, ?string $reason): void
    {
        $this->events->record($state->conversation, 'automation_blocked', 'Automação bloqueada.', $message, null, ['reason' => $reason]);
    }

    private function contactEligible(?Contact $contact): bool
    {
        return $contact
            && $contact->status === ContactStatus::Active
            && ! $contact->do_not_contact
            && filled($contact->phone_normalized);
    }

    /**
     * Controle manual pelo operador.
     */
    public function pause(ConversationFlowState $state, User $user): void
    {
        $state->forceFill(['is_paused' => true])->save();
        $this->machine->transition($state, ConversationFlowStage::Paused, 'paused_by_user', null, null, $user);
        $this->audit->log('conversation_automation.paused', 'Automação pausada.', $state, null, ['conversation_id' => $state->conversation_id], $user);
    }

    public function resume(ConversationFlowState $state, User $user): void
    {
        $destino = $this->machine->stageToResume($state);

        $state->forceFill([
            'is_paused' => false,
            'needs_human_review' => false,
            'stage_before_hold' => null,
        ])->save();

        $this->machine->transition($state, $destino, 'resumed_by_user', null, null, $user);
        $this->audit->log('conversation_automation.resumed', 'Automação retomada.', $state, null, [
            'conversation_id' => $state->conversation_id,
            'stage' => $destino->value,
        ], $user);
    }

    public function finishManually(ConversationFlowState $state, User $user): void
    {
        $this->machine->finish($state, ConversationFlowStage::Completed, 'encerrado_manualmente', 'finished_by_user', null, null, $user);
        $this->audit->log('conversation_automation.finished', 'Automação encerrada manualmente.', $state, null, ['conversation_id' => $state->conversation_id], $user);
    }

    public function takeOver(ConversationFlowState $state, User $user): void
    {
        $state->forceFill(['is_paused' => true, 'needs_human_review' => true])->save();
        $this->machine->transition($state, ConversationFlowStage::WaitingHuman, 'taken_over_by_user', null, null, $user);
        $this->audit->log('conversation_automation.taken_over', 'Conversa assumida manualmente.', $state, null, ['conversation_id' => $state->conversation_id], $user);
    }
}
