<?php

namespace App\Services\InboundAttendance;

use App\Enums\ConsentStatus;
use App\Enums\ContactSource;
use App\Enums\ContactStatus;
use App\Enums\ConversationFlowStage;
use App\Enums\InboundAttendanceOutcome;
use App\Enums\InboundOpeningMode;
use App\Enums\ReplySuggestionAction;
use App\Enums\ReplySuggestionStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\ConversationReplySuggestion;
use App\Models\InboundAttendanceAttempt;
use App\Models\InboundAttendanceProfile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ConversationAutomation\ConversationAutomatedReplyService;
use App\Services\ConversationAutomation\ConversationFlowStateMachine;
use App\Services\Contacts\ContactDuplicateService;
use App\Services\Contacts\ContactHistoryService;
use App\Services\Contacts\PhoneNormalizerService;
use App\Services\Conversations\ConversationEventService;
use App\Services\ResponseGeneration\AiConversationResponseGenerator;
use App\Services\SystemSettingService;
use Illuminate\Support\Str;
use Throwable;

/**
 * Abre atendimento automático para quem escreveu primeiro.
 *
 * Até aqui todo fluxo nascia de um lote: o sistema falava, a pessoa respondia,
 * e o motor sabia o que fazer porque tinha aberto a conversa. Quem escrevia por
 * conta própria caía num `handleIncomingMessage` sem estado, e o motor saía
 * calado — o que virava atendimento humano quando havia gente olhando, e
 * silêncio quando não havia.
 *
 * A diferença de ordem muda o texto de abertura. Quem chega por lote não disse
 * nada ainda, e a apresentação é a primeira frase da conversa. Aqui a pessoa já
 * escreveu, e já escreveu alguma coisa específica: abrir com a apresentação por
 * cima de uma pergunta é responder outra coisa. Por isso o modo padrão responde
 * primeiro e apresenta a pesquisa na mesma mensagem — e, quando não há resposta
 * confiável para dar, não apresenta nada e deixa a conversa na fila.
 */
class InboundAttendanceService
{
    public function __construct(
        private readonly InboundAttendanceRouter $router,
        private readonly InboundAttendanceGuard $guard,
        private readonly ConversationAutomatedReplyService $replies,
        private readonly ConversationFlowStateMachine $machine,
        private readonly AiConversationResponseGenerator $generator,
        private readonly ConversationEventService $events,
        private readonly PhoneNormalizerService $phones,
        private readonly ContactDuplicateService $duplicates,
        private readonly SystemSettingService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Avalia uma mensagem recebida e, podendo, abre a conversa automática.
     *
     * @param  ?User  $user  Quem clicou em iniciar na fila. Nulo é o automático.
     * @param  ?InboundAttendanceProfile  $forced  Perfil escolhido à mão, que
     *                                             substitui o roteamento.
     * @return array{outcome: InboundAttendanceOutcome, reason: ?string, profile: ?InboundAttendanceProfile}
     */
    public function handle(ConversationMessage $message, ?User $user = null, ?InboundAttendanceProfile $forced = null): array
    {
        $conversation = $message->conversation;

        if (! $conversation) {
            return $this->result(InboundAttendanceOutcome::Skipped, 'conversa_inexistente', null);
        }

        $manual = $user !== null;

        /*
         | Robô e operadora não entram.
         |
         | Nem toda mensagem recebida é alguém falando com a gente: operadora
         | avisa saldo, banco manda código, robô de recarga oferece serviço. O
         | atendimento responderia a todos, apresentando uma pesquisa eleitoral
         | a um sistema que não lê — e cada um desses ainda ocuparia uma linha
         | da fila, ensinando a ignorar a fila.
         |
         | Vale só para o automático. Quem clica está olhando a conversa e viu
         | o que tem nela; a exclusão existe para poupar atenção, não para
         | contrariar quem já prestou atenção.
         */
        if (! $manual && ($expressao = $this->router->exclusionMatch($message)) !== null) {
            return $this->record($conversation, $message, null, InboundAttendanceOutcome::Skipped, 'mensagem_ignorada', null, [
                'expressao' => $expressao,
            ]);
        }

        $routed = $forced
            ? ['profile' => $forced, 'matched' => null]
            : $this->router->route($message);

        $profile = $routed['profile'];

        $check = $this->guard->canStart($conversation, $profile, $manual, $message);

        if (! $check['allowed']) {
            return $this->record($conversation, $message, $profile, InboundAttendanceOutcome::Blocked, $check['reason'], $user, [
                'expressao' => $routed['matched'],
            ]);
        }

        $contact = $conversation->contact ?: $this->resolveContact($conversation, $message);

        if (! $contact instanceof Contact) {
            return $this->record($conversation, $message, $profile, InboundAttendanceOutcome::Blocked, $contact, $user);
        }

        if (! $this->guard->contactEligible($contact)) {
            return $this->record($conversation, $message, $profile, InboundAttendanceOutcome::Blocked,
                $contact->do_not_contact ? 'contato_nao_contatar' : 'contato_inativo', $user);
        }

        $conversation->setRelation('contact', $contact);

        $flow = $profile->conversationFlow;
        $state = ConversationFlowState::create([
            'conversation_id' => $conversation->id,
            'conversation_flow_id' => $profile->conversation_flow_id,
            'inbound_attendance_profile_id' => $profile->id,
            'current_stage' => ConversationFlowStage::Inactive,
            'automated_messages_count' => 0,
            'attempts_count' => 0,
            'last_processed_message_id' => $message->id,
            'started_at' => now(),
            'expires_at' => now()->addHours(max(1, (int) ($flow?->validity_hours ?: $this->settings->get('conversation_automation.default_validity_hours', 48)))),
        ]);

        $state->setRelation('conversation', $conversation);
        $state->setRelation('flow', $flow);

        $opening = $this->composeOpening($profile, $state, $message);

        if ($opening['body'] === null) {
            /*
             | Nada saiu: desfaz o estado.
             |
             | Um estado sem mensagem nenhuma é pior que estado nenhum. Ele faz
             | a conversa parecer atendida — some da fila, e a guarda passa a
             | recusar toda nova tentativa com `conversa_ja_tem_fluxo` —, e do
             | lado de quem escreveu continua o mesmo silêncio.
             */
            $state->delete();

            return $this->record($conversation, $message, $profile, InboundAttendanceOutcome::Blocked, $opening['reason'], $user);
        }

        $queued = $this->replies->queue($state, $opening['body'], 'inbound_attendance_opening_queued', [
            'perfil_id' => $profile->id,
            'modo' => $profile->opening_mode->value,
            'expressao' => $routed['matched'],
        ], safetyNet: true);

        if (! $queued) {
            $state->delete();

            return $this->record($conversation, $message, $profile, InboundAttendanceOutcome::Blocked, 'envio_recusado', $user);
        }

        if ($opening['suggestion'] instanceof ConversationReplySuggestion) {
            // O texto gerado saiu de verdade — dentro da mensagem de abertura.
            // Deixá-lo vivo faria a tela de sugestões pedir aprovação de um
            // texto que a pessoa já leu.
            $opening['suggestion']->forceFill([
                'status' => ReplySuggestionStatus::Sent,
                'active_source_message_id' => null,
                'sent_message_id' => $queued->id,
                'sent_at' => now(),
                'auto_sent' => true,
            ])->save();
        }

        $this->machine->transition($state, ConversationFlowStage::InitialMessageSent, 'inbound_attendance_opening_sent');
        $this->machine->transition($state, ConversationFlowStage::WaitingPermission, 'awaiting_permission');

        if ($manual) {
            // Homologação: o perfil se solta depois de N conversas que uma
            // pessoa leu e aprovou. Contar as automáticas aqui esvaziaria a
            // trava — ela existe justamente para exigir olho humano.
            $profile->increment('approved_starts_count');

            if ($profile->approved_starts_count >= $profile->homologation_threshold && $profile->homologated_at === null) {
                $profile->forceFill(['homologated_at' => now()])->save();
            }
        }

        return $this->record($conversation, $message, $profile, InboundAttendanceOutcome::Started, null, $user, [
            'expressao' => $routed['matched'],
            'modo' => $profile->opening_mode->value,
            'mensagem_id' => $queued->id,
        ]);
    }

    /**
     * Aplica as expressões de exclusão ao que já está parado na fila.
     *
     * A exclusão age quando a mensagem é processada, e isso deixa de fora
     * justamente o que motivou a lista: o aviso de operadora que já estava na
     * fila quando alguém percebeu que ele não devia estar ali. Sem isto, a
     * pessoa acrescenta a frase, olha a fila e vê a mesma linha no mesmo lugar.
     *
     * Roda ao salvar a lista e pelo comando, nunca sozinha: é uma varredura, e
     * varredura que acontece sem ninguém pedir some do radar quando erra.
     *
     * @return int Quantas conversas saíram da fila.
     */
    public function applyExclusionsToPending(int $limit = 200): int
    {
        $removidas = 0;

        $conversas = app(InboundAttendanceQueue::class)->pending()
            ->orderByDesc('last_incoming_message_at')
            ->limit($limit)
            ->get();

        foreach ($conversas as $conversa) {
            $mensagem = ConversationMessage::query()
                ->where('conversation_id', $conversa->id)
                ->where('direction', \App\Enums\ConversationMessageDirection::Incoming)
                ->latest('id')
                ->first();

            if (! $mensagem) {
                continue;
            }

            $expressao = $this->router->exclusionMatch($mensagem);

            if ($expressao === null) {
                continue;
            }

            $mensagem->setRelation('conversation', $conversa);
            $this->record($conversa, $mensagem, null, InboundAttendanceOutcome::Skipped, 'mensagem_ignorada', null, [
                'expressao' => $expressao,
                'retroativa' => true,
            ]);

            $removidas++;
        }

        return $removidas;
    }

    /**
     * Texto da abertura.
     *
     * @return array{body: ?string, reason: ?string, suggestion: ?ConversationReplySuggestion}
     */
    private function composeOpening(InboundAttendanceProfile $profile, ConversationFlowState $state, ConversationMessage $message): array
    {
        $presentation = trim((string) ($profile->presentation_text ?: $state->flow?->presentation_text));

        if ($profile->opening_mode === InboundOpeningMode::SurveyOnly) {
            return $presentation === ''
                ? ['body' => null, 'reason' => 'sem_texto_de_abertura', 'suggestion' => null]
                : ['body' => $presentation, 'reason' => null, 'suggestion' => null];
        }

        $suggestion = $this->generateAnswer($message, $state);

        if (! $suggestion) {
            /*
             | Sem resposta confiável, nada sai.
             |
             | A alternativa seria mandar só a apresentação — e a apresentação,
             | por cima de uma pergunta que a pessoa fez, é responder outra
             | coisa. Quem faz isso ao telefone é atendimento ruim, e a versão
             | automática é pior porque não tem como a pessoa insistir.
             |
             | A conversa fica na fila com o motivo à vista. O piso continua
             | existindo: `conversations:answer-pending` avisa que a mensagem
             | chegou, quinze minutos depois, e agora encontra um contato
             | identificado porque este serviço já o criou.
             */
            return ['body' => null, 'reason' => 'resposta_ia_indisponivel', 'suggestion' => null];
        }

        $answer = $suggestion->outgoingText();

        return [
            'body' => $presentation === '' ? $answer : $answer."\n\n".$presentation,
            'reason' => null,
            'suggestion' => $suggestion,
        ];
    }

    /**
     * Gera a resposta ao que a pessoa escreveu, sem enviá-la.
     *
     * A geração passa longe de `ConversationSuggestionService`: lá, terminada a
     * geração, o autoenvio pode disparar sozinho — e o texto sairia sem a
     * apresentação, que é justamente o que esta abertura precisa juntar numa
     * mensagem só.
     *
     * O critério de aceite é o mesmo da rede de segurança, e é mais exigente
     * que o autoenvio comum de propósito: aqui ninguém leu o texto antes, e é a
     * primeira coisa que essa pessoa vai ler da gente.
     */
    private function generateAnswer(ConversationMessage $message, ConversationFlowState $state): ?ConversationReplySuggestion
    {
        try {
            $suggestion = $this->generator->generate($message, $state);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return $suggestion && $this->openingAnswerUsable($suggestion) ? $suggestion : null;
    }

    /**
     * Esta resposta pode abrir uma conversa?
     *
     * Público e separado da geração para poder ser cobrado por teste sem
     * envolver o provedor: o que importa aqui é a régua, não a chamada.
     */
    public function openingAnswerUsable(ConversationReplySuggestion $suggestion): bool
    {
        if ($suggestion->requires_human_review || $suggestion->handoff_reason !== null) {
            return false;
        }

        /*
         | Só resposta que responde. Encerramento não abre conversa.
         |
         | `producesText()` aceita o agradecimento de encerramento, e colá-lo na
         | apresentação produz uma mensagem que se contradiz. Saiu assim, para
         | uma pessoa de verdade: "Agradeço por participar da pesquisa. Se
         | quiser compartilhar mais ideias no futuro, estamos à disposição." e,
         | logo abaixo, "Posso te fazer uma pergunta rápida?".
         |
         | Pedido de esclarecimento tem o mesmo defeito pelo avesso: perguntar o
         | que a pessoa quis dizer e, na mesma mensagem, mudar de assunto para a
         | pesquisa.
         */
        if ($suggestion->action !== ReplySuggestionAction::SuggestReply) {
            return false;
        }

        if ($suggestion->outgoingText() === '') {
            return false;
        }

        $threshold = (float) $this->settings->get('ai.response.safety_net_min_confidence', 0.92);

        if ($suggestion->confidence === null || (float) $suggestion->confidence < $threshold) {
            return false;
        }

        return true;
    }

    /**
     * Contato da conversa, criado agora se ainda não existir.
     *
     * Criar só ao iniciar, e não na chegada da mensagem, é uma escolha: a base
     * não deve crescer com todo número que mandou um "oi" e nunca mais voltou.
     * Quem chega a este ponto vai receber uma mensagem nossa, e mandar mensagem
     * para quem não está no cadastro é o que nos deixa sem histórico.
     *
     * @return Contact|string O contato, ou o código do motivo que impediu.
     */
    private function resolveContact(Conversation $conversation, ConversationMessage $message): Contact|string
    {
        $phone = (string) ($message->sender_phone_snapshot ?: $conversation->whatsappPhoneDigits());
        $result = $this->phones->normalize($phone);

        if (! $result->valid()) {
            return 'telefone_invalido';
        }

        $existing = $this->duplicates->exactPhone($result->normalized);

        if ($existing) {
            $conversation->forceFill(['contact_id' => $existing->id])->save();
            $this->events->record($conversation, 'contact_matched_on_attendance', 'Contato existente vinculado à conversa.', $message, null, [
                'contact_id' => $existing->id,
            ]);

            return $existing;
        }

        $name = $this->contactName($message, $result->normalized);

        $contact = Contact::create([
            'name' => $name,
            'first_name' => Str::before($name, ' '),
            'phone' => $phone,
            'phone_normalized' => $result->normalized,
            'status' => ContactStatus::Active,
            'source' => ContactSource::Recebido,

            /*
             | Consentimento não é presumido por a pessoa ter escrito.
             |
             | Ela escreveu para nós, o que autoriza responder — e não autoriza
             | incluí-la em campanha. `NotInformed` é o que descreve a situação:
             | ninguém perguntou nada ainda. Quem quiser tratar como opt-in faz
             | isso na tela do contato, com registro de quem decidiu.
             */
            'consent_status' => ConsentStatus::NotInformed,
            'country' => (string) $this->settings->get('contacts.default_country', 'BR'),
            'has_replied' => true,
            'first_replied_at' => $message->received_at ?? now(),
            'last_replied_at' => $message->received_at ?? now(),
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $conversation->forceFill(['contact_id' => $contact->id])->save();

        app(ContactHistoryService::class)->record(
            $contact,
            \App\Enums\ContactHistoryAction::Created,
            'Contato criado pelo atendimento de entrada.',
            null,
            $contact->only(['name', 'phone_normalized', 'status', 'source']),
        );

        $this->events->record($conversation, 'contact_created_on_attendance', 'Contato criado a partir da mensagem recebida.', $message, null, [
            'contact_id' => $contact->id,
        ]);

        $this->audit->log('inbound_attendance.contact_created', 'Contato criado pelo atendimento de entrada.', $contact, null, [
            'conversation_id' => $conversation->id,
            'phone_normalized' => $result->normalized,
        ]);

        return $contact;
    }

    /**
     * Nome do contato novo.
     *
     * O WhatsApp manda o nome do perfil, que é o que a pessoa escolheu ser
     * chamada. Sem ele sobra o telefone — feio numa lista, e ainda assim melhor
     * que "Contato 4821", que não identifica ninguém e não é pesquisável.
     */
    private function contactName(ConversationMessage $message, string $normalizedPhone): string
    {
        $pushName = trim((string) $message->sender_name_snapshot);

        return $pushName !== '' ? Str::limit($pushName, 120, '') : $normalizedPhone;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{outcome: InboundAttendanceOutcome, reason: ?string, profile: ?InboundAttendanceProfile}
     */
    private function record(
        Conversation $conversation,
        ConversationMessage $message,
        ?InboundAttendanceProfile $profile,
        InboundAttendanceOutcome $outcome,
        ?string $reason,
        ?User $user,
        array $metadata = [],
    ): array {
        InboundAttendanceAttempt::create([
            'conversation_id' => $conversation->id,
            'conversation_message_id' => $message->id,
            'inbound_attendance_profile_id' => $profile?->id,
            'outcome' => $outcome,
            'reason' => $reason,
            'started_by' => $user?->id,
            'metadata' => $metadata ?: null,
        ]);

        if ($outcome === InboundAttendanceOutcome::Started) {
            $this->events->record($conversation, 'inbound_attendance_started', 'Atendimento automático iniciado.', $message, null, [
                'perfil' => $profile?->name,
                'manual' => $user !== null,
            ]);

            $this->audit->log('inbound_attendance.started', 'Atendimento automático de entrada iniciado.', $conversation, null, [
                'conversation_id' => $conversation->id,
                'profile_id' => $profile?->id,
                'manual' => $user !== null,
            ]);
        }

        return $this->result($outcome, $reason, $profile);
    }

    /**
     * @return array{outcome: InboundAttendanceOutcome, reason: ?string, profile: ?InboundAttendanceProfile}
     */
    private function result(InboundAttendanceOutcome $outcome, ?string $reason, ?InboundAttendanceProfile $profile): array
    {
        return ['outcome' => $outcome, 'reason' => $reason, 'profile' => $profile];
    }
}
