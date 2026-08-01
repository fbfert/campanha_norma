<?php

namespace Tests\Feature;

use App\Enums\ClassificationSource;
use App\Enums\ContactStatus;
use App\Enums\ConversationFlowStage;
use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageOrigin;
use App\Enums\ConversationMessageStatus;
use App\Enums\HandoffReason;
use App\Enums\InsightReviewReason;
use App\Enums\MessageClassification;
use App\Enums\ReplySuggestionAction;
use App\Enums\ReplySuggestionStatus;
use App\Enums\ResponseGenerationMode;
use App\Enums\SuggestionFeedback;
use App\Events\ConversationMessageEvaluated;
use App\Jobs\GenerateConversationReplyJob;
use App\Jobs\SendApprovedReplyJob;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowQuestion;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageClassification;
use App\Models\ConversationReplySuggestion;
use App\Models\Role;
use App\Models\User;
use App\Services\ResponseGeneration\ConversationSuggestionService;
use App\Services\ResponseGeneration\ReplyTextValidator;
use App\Services\ResponseGeneration\SuggestionApprovalService;
use App\Services\SystemSettingService;
use Database\Seeders\InsightTopicSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SendingSettingSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Subetapa 9C: geração de resposta, aprovação humana e handoff.
 *
 * O eixo destes testes e um so: nada gerado chega a uma pessoa sem passar por
 * aprovação explícita ou por todos os guards do autoenvio.
 */
class ConversationResponseGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        $this->seed(SendingSettingSeeder::class);
        $this->seed(InsightTopicSeeder::class);

        Config::set('ai.provider', 'openai');
        Config::set('ai.providers.openai.key', 'chave-de-teste');
        Config::set('ai.providers.openai.model', 'modelo-de-teste');

        $this->mode(ResponseGenerationMode::ApprovalRequired);
    }

    // --- Ajudantes -----------------------------------------------------------

    /** @param array<string, string> $extra */
    private function settings(array $extra): void
    {
        app(SystemSettingService::class)->updateMany($extra);
    }

    private function mode(ResponseGenerationMode $mode): void
    {
        $this->settings([
            'ai.enabled' => '1',
            'ai.analysis_enabled' => '1',
            'ai.retry_backoff_ms' => '0',
            'ai.response.mode' => $mode->value,
            'conversation_automation.enabled' => '1',
            'conversation_automation.auto_send_enabled' => '1',
            'conversation_automation.window_start' => '00:00',
            'conversation_automation.window_end' => '23:59',
        ]);
    }

    /** @return array{0: Contact, 1: Conversation, 2: ConversationFlowState} */
    private function scenario(): array
    {
        $contact = Contact::factory()->create([
            'status' => ContactStatus::Active,
            'do_not_contact' => false,
            'phone_normalized' => '5549999990001',
        ]);

        $flow = ConversationFlow::factory()->create(['max_followups' => 0]);
        $question = ConversationFlowQuestion::factory()->create(['conversation_flow_id' => $flow->id]);
        $conversation = Conversation::factory()->create(['contact_id' => $contact->id]);

        $state = ConversationFlowState::factory()->create([
            'conversation_id' => $conversation->id,
            'conversation_flow_id' => $flow->id,
            'current_stage' => ConversationFlowStage::WaitingAnswer,
            'selected_question_id' => $question->id,
            'selected_question_snapshot' => 'O que a Professora Norma pode fazer para melhorar nosso Estado?',
        ]);

        return [$contact, $conversation, $state->fresh()];
    }

    private function incoming(Conversation $conversation, string $body = 'Falta médico especialista na minha cidade.'): ConversationMessage
    {
        return ConversationMessage::factory()->create([
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'direction' => ConversationMessageDirection::Incoming,
            'message_type' => 'text',
            'body' => $body,
        ]);
    }

    private function classify(ConversationMessage $message, MessageClassification $classification, ?string $reviewReason = null): void
    {
        ConversationMessageClassification::create([
            'conversation_id' => $message->conversation_id,
            'conversation_message_id' => $message->id,
            'purpose' => 'classify',
            'classification' => $classification,
            'source' => ClassificationSource::Ai,
            'confidence' => 0.95,
            'requires_human_review' => $reviewReason !== null,
            'review_reason' => $reviewReason,
            'prompt_version' => 'v1',
            'schema_version' => 1,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function fakeGeneration(array $payload = []): void
    {
        Http::fake([
            '*' => Http::response([
                'model' => 'modelo-de-teste',
                'choices' => [['message' => ['content' => json_encode(array_merge([
                    'action' => 'suggest_reply',
                    'reply_text' => 'Obrigada por explicar. O maior problema hoje e a falta de profissionais ou a distância até o atendimento?',
                    'follow_up_type' => 'clarification',
                    'topic' => 'saude',
                    'confidence' => 0.92,
                    'requires_human_review' => false,
                    'handoff_reason' => null,
                ], $payload))]]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 30, 'total_tokens' => 130],
            ]),
        ]);
    }

    private function generate(ConversationMessage $message): ?ConversationReplySuggestion
    {
        return app(ConversationSuggestionService::class)->handleIncoming($message);
    }

    private function userWith(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', $roleSlug)->firstOrFail());

        return $user;
    }

    private function outgoingCount(Conversation $conversation): int
    {
        return ConversationMessage::where('conversation_id', $conversation->id)
            ->where('direction', ConversationMessageDirection::Outgoing)
            ->count();
    }

    // =========================================================================
    // Critério: nada e enviado sem aprovação
    // =========================================================================

    public function test_approval_required_creates_a_pending_suggestion_and_sends_nothing(): void
    {
        $this->fakeGeneration();
        [, $conversation] = $this->scenario();

        $suggestion = $this->generate($this->incoming($conversation));

        $this->assertNotNull($suggestion);
        $this->assertSame(ReplySuggestionStatus::Pending, $suggestion->status);
        $this->assertSame(ReplySuggestionAction::SuggestReply, $suggestion->action);
        $this->assertSame(0, $this->outgoingCount($conversation), 'Nada pode ser enviado sem aprovação.');
        $this->assertNull($suggestion->sent_message_id);
    }

    public function test_draft_only_creates_the_suggestion_but_refuses_to_send(): void
    {
        $this->mode(ResponseGenerationMode::DraftOnly);
        $this->fakeGeneration();
        [, $conversation] = $this->scenario();

        $suggestion = $this->generate($this->incoming($conversation));
        $this->assertNotNull($suggestion);

        $result = app(ConversationSuggestionService::class)->send($suggestion, $this->userWith('administrador'));

        $this->assertFalse($result['sent']);
        $this->assertSame('modo_nao_permite_envio', $result['reason']);
        $this->assertSame(0, $this->outgoingCount($conversation));
    }

    public function test_disabled_mode_never_calls_the_provider(): void
    {
        $this->mode(ResponseGenerationMode::Disabled);
        Http::fake();
        [, $conversation] = $this->scenario();

        $this->assertNull($this->generate($this->incoming($conversation)));

        Http::assertNothingSent();
        $this->assertSame(0, ConversationReplySuggestion::count());
    }

    public function test_the_flow_mode_may_only_restrict(): void
    {
        $this->mode(ResponseGenerationMode::AutoSendLimited);
        $this->fakeGeneration();
        [, $conversation, $state] = $this->scenario();

        // Fluxo mais restritivo que o global vence.
        $state->flow->update(['response_mode' => ResponseGenerationMode::DraftOnly]);

        $suggestion = $this->generate($this->incoming($conversation));

        $this->assertSame(ResponseGenerationMode::DraftOnly, $suggestion->mode);
        $this->assertSame(0, $this->outgoingCount($conversation));
    }

    public function test_the_flow_mode_cannot_widen_the_global_mode(): void
    {
        $this->mode(ResponseGenerationMode::DraftOnly);
        $this->fakeGeneration();
        [, $conversation, $state] = $this->scenario();

        // Fluxo tentando ser mais permissivo que o global.
        $state->flow->update(['response_mode' => ResponseGenerationMode::AutoSendLimited]);

        $suggestion = $this->generate($this->incoming($conversation));

        $this->assertSame(ResponseGenerationMode::DraftOnly, $suggestion->mode);
        $this->assertSame(0, $this->outgoingCount($conversation));
    }

    // =========================================================================
    // Aprovação humana
    // =========================================================================

    public function test_approval_sends_and_stores_generated_and_final_text_separately(): void
    {
        Queue::fake();
        $this->fakeGeneration();
        [, $conversation] = $this->scenario();
        $suggestion = $this->generate($this->incoming($conversation));
        $original = $suggestion->generated_text;

        $admin = $this->userWith('administrador');

        $this->actingAs($admin)
            ->post(route('admin.reply-suggestions.approve', $suggestion), [
                'final_text' => 'Obrigada por explicar. Na sua região, falta profissional ou falta transporte?',
            ])
            ->assertSessionHas('success');

        $suggestion->refresh();
        $this->assertSame(ReplySuggestionStatus::Sent, $suggestion->status);
        $this->assertSame($original, $suggestion->generated_text, 'O texto gerado nunca e sobrescrito.');
        $this->assertNotSame($original, $suggestion->final_text);
        $this->assertTrue($suggestion->wasEdited());
        $this->assertSame($admin->id, $suggestion->approved_by);
        $this->assertNotNull($suggestion->approved_at);

        $message = ConversationMessage::findOrFail($suggestion->sent_message_id);
        $this->assertSame(ConversationMessageOrigin::ApprovedAi, $message->origin);
        $this->assertTrue($message->generated_by_ai);
        $this->assertSame($admin->id, $message->approved_by);
        $this->assertStringContainsString('falta transporte', (string) $message->body);
        Queue::assertPushed(SendApprovedReplyJob::class);
    }

    public function test_approval_requires_the_specific_permission(): void
    {
        $this->fakeGeneration();
        [, $conversation] = $this->scenario();
        $suggestion = $this->generate($this->incoming($conversation));

        // Operador ve e rejeita, mas não aprova.
        $this->actingAs($this->userWith('operador'))
            ->post(route('admin.reply-suggestions.approve', $suggestion))
            ->assertForbidden();

        $this->actingAs($this->userWith('operador'))
            ->get(route('admin.reply-suggestions.index'))
            ->assertOk();

        $this->assertSame(0, $this->outgoingCount($conversation));
    }

    public function test_rejection_takes_the_suggestion_out_of_circulation(): void
    {
        $this->fakeGeneration();
        [, $conversation] = $this->scenario();
        $suggestion = $this->generate($this->incoming($conversation));

        $this->actingAs($this->userWith('operador'))
            ->post(route('admin.reply-suggestions.reject', $suggestion), ['reason' => 'Texto genérico demais.'])
            ->assertSessionHas('success');

        $suggestion->refresh();
        $this->assertSame(ReplySuggestionStatus::Rejected, $suggestion->status);
        $this->assertNull($suggestion->active_source_message_id);
        $this->assertSame('Texto genérico demais.', $suggestion->rejection_reason);
        $this->assertSame(0, $this->outgoingCount($conversation));
    }

    public function test_regeneration_requires_a_justification_and_keeps_history(): void
    {
        $this->fakeGeneration();
        [, $conversation] = $this->scenario();
        $suggestion = $this->generate($this->incoming($conversation));
        $admin = $this->userWith('administrador');

        $this->actingAs($admin)
            ->post(route('admin.reply-suggestions.regenerate', $suggestion), [])
            ->assertSessionHasErrors('justification');

        $this->actingAs($admin)
            ->post(route('admin.reply-suggestions.regenerate', $suggestion), ['justification' => 'Pergunta repetida.'])
            ->assertRedirect();

        $suggestion->refresh();
        $this->assertSame(ReplySuggestionStatus::Superseded, $suggestion->status);
        $this->assertSame('Pergunta repetida.', $suggestion->regeneration_reason);

        $this->assertSame(2, ConversationReplySuggestion::count(), 'A sugestão anterior continua legível.');
        $this->assertSame(2, ConversationReplySuggestion::max('generation_attempt'));
    }

    public function test_the_approval_inbox_has_no_bulk_action(): void
    {
        $this->fakeGeneration();
        [, $conversation] = $this->scenario();
        $this->generate($this->incoming($conversation));

        $response = $this->actingAs($this->userWith('administrador'))
            ->get(route('admin.reply-suggestions.index'))
            ->assertOk();

        $html = $response->getContent();
        $this->assertStringNotContainsString('aprovar-todas', $html);
        $this->assertStringNotContainsString('bulk', $html);
        $this->assertStringNotContainsString('type="checkbox"', $html);
    }

    // =========================================================================
    // Sugestão obsoleta e concorrência
    // =========================================================================

    public function test_a_newer_incoming_message_blocks_the_approval(): void
    {
        $this->fakeGeneration();
        [, $conversation] = $this->scenario();
        $suggestion = $this->generate($this->incoming($conversation));

        // A pessoa escreve de novo antes de o operador aprovar.
        $this->incoming($conversation, 'Na verdade o problema maior e o transporte.');

        $this->actingAs($this->userWith('administrador'))
            ->post(route('admin.reply-suggestions.approve', $suggestion))
            ->assertSessionHas('error');

        $suggestion->refresh();
        $this->assertSame(ReplySuggestionStatus::Superseded, $suggestion->status);
        $this->assertNull($suggestion->active_source_message_id);
        $this->assertSame(0, $this->outgoingCount($conversation));
    }

    public function test_concurrent_approval_sends_only_once(): void
    {
        Queue::fake();
        $this->fakeGeneration();
        [, $conversation] = $this->scenario();
        $suggestion = $this->generate($this->incoming($conversation));

        $approvals = app(SuggestionApprovalService::class);
        $first = $approvals->approveAndSend($suggestion, $this->userWith('administrador'));
        $second = $approvals->approveAndSend($suggestion->refresh(), $this->userWith('administrador'));

        $this->assertTrue($first['sent']);
        $this->assertFalse($second['sent'], 'A segunda aprovação não pode enviar de novo.');
        $this->assertSame(1, $this->outgoingCount($conversation));
    }

    public function test_only_one_live_suggestion_exists_per_incoming_message(): void
    {
        $this->fakeGeneration();
        [, $conversation] = $this->scenario();
        $message = $this->incoming($conversation);

        $this->generate($message);
        $this->generate($message->refresh());

        $live = ConversationReplySuggestion::whereNotNull('active_source_message_id')->count();
        $this->assertSame(1, $live, 'No máximo uma sugestão viva por mensagem recebida.');
    }

    // =========================================================================
    // Autoenvio limitado
    // =========================================================================

    public function test_auto_send_is_refused_when_the_allowlist_is_empty(): void
    {
        $this->mode(ResponseGenerationMode::AutoSendLimited);
        $this->fakeGeneration();
        [, $conversation] = $this->scenario();
        $message = $this->incoming($conversation);
        $this->classify($message, MessageClassification::QuestionAnswer);

        $this->generate($message);

        $this->assertSame(0, $this->outgoingCount($conversation), 'Allowlist vazia bloqueia tudo.');
        $this->assertDatabaseHas('conversation_events', ['event_type' => 'ai_auto_send_decision']);
    }

    public function test_auto_send_works_when_every_guard_passes(): void
    {
        Queue::fake();
        $this->mode(ResponseGenerationMode::AutoSendLimited);
        $this->settings([
            'ai.response.auto_send_classifications' => 'question_answer',
            'ai.response.auto_send_min_confidence' => '0.80',
        ]);
        $this->fakeGeneration();

        [, $conversation] = $this->scenario();
        $message = $this->incoming($conversation);
        $this->classify($message, MessageClassification::QuestionAnswer);

        $suggestion = $this->generate($message);

        $this->assertSame(ReplySuggestionStatus::Sent, $suggestion->status);
        $this->assertTrue($suggestion->auto_sent);
        $this->assertNull($suggestion->approved_by, 'Autoenvio não tem aprovador humano.');
        $this->assertSame(1, $this->outgoingCount($conversation));
    }

    public function test_auto_send_is_refused_below_the_confidence_threshold(): void
    {
        $this->mode(ResponseGenerationMode::AutoSendLimited);
        $this->settings([
            'ai.response.auto_send_classifications' => 'question_answer',
            'ai.response.auto_send_min_confidence' => '0.95',
        ]);
        $this->fakeGeneration(['confidence' => 0.80]);

        [, $conversation] = $this->scenario();
        $message = $this->incoming($conversation);
        $this->classify($message, MessageClassification::QuestionAnswer);

        $this->generate($message);

        $this->assertSame(0, $this->outgoingCount($conversation));
    }

    public function test_auto_send_is_refused_when_the_conversation_is_assigned_to_a_human(): void
    {
        $this->mode(ResponseGenerationMode::AutoSendLimited);
        $this->settings([
            'ai.response.auto_send_classifications' => 'question_answer',
            'ai.response.auto_send_min_confidence' => '0.50',
        ]);
        $this->fakeGeneration();

        [, $conversation] = $this->scenario();
        $conversation->update(['assigned_user_id' => $this->userWith('operador')->id]);
        $message = $this->incoming($conversation);
        $this->classify($message, MessageClassification::QuestionAnswer);

        $this->generate($message);

        $this->assertSame(0, $this->outgoingCount($conversation));
    }

    public function test_auto_send_never_duplicates(): void
    {
        Queue::fake();
        $this->mode(ResponseGenerationMode::AutoSendLimited);
        $this->settings([
            'ai.response.auto_send_classifications' => 'question_answer',
            'ai.response.auto_send_min_confidence' => '0.50',
        ]);
        $this->fakeGeneration();

        [, $conversation] = $this->scenario();
        $message = $this->incoming($conversation);
        $this->classify($message, MessageClassification::QuestionAnswer);

        $suggestion = $this->generate($message);
        app(ConversationSuggestionService::class)->send($suggestion->refresh(), null, true);

        $this->assertSame(1, $this->outgoingCount($conversation));
    }

    // =========================================================================
    // Opt-out e elegibilidade entre geração e envio
    // =========================================================================

    public function test_opt_out_after_generation_blocks_the_send(): void
    {
        $this->fakeGeneration();
        [$contact, $conversation] = $this->scenario();
        $suggestion = $this->generate($this->incoming($conversation));

        // A pessoa pede para parar depois da sugestão criada.
        $contact->update(['do_not_contact' => true]);

        $result = app(ConversationSuggestionService::class)->send($suggestion, $this->userWith('administrador'));

        $this->assertFalse($result['sent']);
        $this->assertSame('contato_nao_contatar', $result['reason']);
        $this->assertSame(0, $this->outgoingCount($conversation));
    }

    public function test_contact_deactivated_after_generation_blocks_the_send(): void
    {
        $this->fakeGeneration();
        [$contact, $conversation] = $this->scenario();
        $suggestion = $this->generate($this->incoming($conversation));

        $contact->update(['status' => ContactStatus::Inactive]);

        $result = app(ConversationSuggestionService::class)->send($suggestion, $this->userWith('administrador'));

        $this->assertFalse($result['sent']);
        $this->assertSame('contato_inativo', $result['reason']);
        $this->assertSame(0, $this->outgoingCount($conversation));
    }

    public function test_an_opted_out_flow_invalidates_pending_suggestions(): void
    {
        $this->fakeGeneration();
        [, $conversation, $state] = $this->scenario();
        $suggestion = $this->generate($this->incoming($conversation));

        $state->forceFill(['current_stage' => ConversationFlowStage::OptedOut])->save();

        $this->generate($this->incoming($conversation, 'não quero mais nada'));

        $suggestion->refresh();
        $this->assertSame(ReplySuggestionStatus::Superseded, $suggestion->status);
        $this->assertNull($suggestion->active_source_message_id);
    }

    // =========================================================================
    // Limite de aprofundamentos
    // =========================================================================

    public function test_reaching_the_limit_thanks_and_completes(): void
    {
        $this->settings(['ai.response.max_followups' => '2']);
        $this->fakeGeneration();
        [, $conversation, $state] = $this->scenario();

        $state->forceFill(['followups_count' => 2])->save();

        $this->assertNull($this->generate($this->incoming($conversation)));

        $state->refresh();
        $this->assertSame(ConversationFlowStage::Completed, $state->current_stage);
        $this->assertSame('limite_de_aprofundamentos', $state->end_reason);
        // O agradecimento e uma mensagem automática da 9A, não texto gerado.
        $this->assertSame(1, $this->outgoingCount($conversation));
        $this->assertSame(
            ConversationMessageOrigin::Automation,
            ConversationMessage::where('conversation_id', $conversation->id)
                ->where('direction', ConversationMessageDirection::Outgoing)->first()->origin
        );
    }

    public function test_the_turn_counter_only_increases_on_a_confirmed_send(): void
    {
        Queue::fake();
        $this->settings(['ai.response.max_followups' => '2']);
        $this->fakeGeneration();
        [, $conversation, $state] = $this->scenario();

        $first = $this->generate($this->incoming($conversation));
        $this->assertSame(0, $state->refresh()->followups_count, 'Gerar não conta turno.');

        app(SuggestionApprovalService::class)->approveAndSend($first, $this->userWith('administrador'));

        $this->assertSame(1, $state->refresh()->followups_count, 'Apenas o envio conta.');
    }

    // =========================================================================
    // Handoff
    // =========================================================================

    #[DataProvider('handoffClassifications')]
    public function test_sensitive_classifications_trigger_handoff_without_generating(string $classification, string $expectedReason): void
    {
        Http::fake();
        [, $conversation, $state] = $this->scenario();
        $message = $this->incoming($conversation);
        $this->classify($message, MessageClassification::from($classification));

        $this->assertNull($this->generate($message));

        Http::assertNothingSent();
        $state->refresh();
        $this->assertTrue($state->is_paused, 'Handoff pausa a automação.');
        $this->assertTrue($state->needs_human_review);
        $this->assertSame(ConversationFlowStage::WaitingHuman, $state->current_stage);
        $this->assertSame(0, $this->outgoingCount($conversation), 'Handoff nunca envia texto improvisado.');

        $this->assertDatabaseHas('conversation_events', [
            'event_type' => 'automation_handoff',
        ]);
        $this->assertSame($expectedReason, HandoffReason::fromClassification(MessageClassification::from($classification))?->value);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function handoffClassifications(): array
    {
        return [
            'pedido de humano' => ['human_requested', 'explicit_request'],
            'denuncia' => ['sensitive_report', 'report_or_accusation'],
            'ofensa' => ['insult_or_abuse', 'hostile_content'],
            'reclamacao' => ['complaint', 'hostile_content'],
            'pergunta factual' => ['asks_about_norma', 'factual_question'],
            'midia' => ['media_or_unsupported', 'unsupported_media'],
        ];
    }

    public function test_a_threat_raises_the_conversation_priority(): void
    {
        Http::fake();
        [, $conversation, $state] = $this->scenario();
        $message = $this->incoming($conversation);
        $this->classify($message, MessageClassification::QuestionAnswer, InsightReviewReason::Threat->value);

        $this->generate($message);

        $this->assertSame('high', $conversation->refresh()->priority->value);
    }

    public function test_the_model_asking_for_handoff_pauses_and_sends_nothing(): void
    {
        $this->fakeGeneration([
            'action' => 'handoff_human',
            'reply_text' => null,
            'handoff_reason' => 'factual_question',
        ]);

        [, $conversation, $state] = $this->scenario();
        $suggestion = $this->generate($this->incoming($conversation, 'Qual e a proposta dela para a saúde?'));

        $this->assertSame(ReplySuggestionAction::HandoffHuman, $suggestion->action);
        $this->assertNull($suggestion->generated_text);
        $this->assertTrue($state->refresh()->is_paused);
        $this->assertSame(0, $this->outgoingCount($conversation));
    }

    // =========================================================================
    // Validação determinística do texto
    // =========================================================================

    #[DataProvider('forbiddenTexts')]
    public function test_forbidden_generated_text_is_blocked(string $text, string $expectedError): void
    {
        $result = app(ReplyTextValidator::class)->validate($text);

        $this->assertFalse($result['valid'], "Esperava reprovação para: {$text}");
        $this->assertContains($expectedError, $result['errors']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function forbiddenTexts(): array
    {
        return [
            'duas perguntas' => ['Entendi. E a distância? E o transporte?', 'mais_de_uma_pergunta'],
            'promessa' => ['Obrigada. Vamos resolver isso na sua cidade.', 'promessa'],
            'pedido de voto' => ['Obrigada pelo relato. Conto com seu voto.', 'pedido_de_voto'],
            'comparacao' => ['Obrigada. Diferente dos outros, ela escuta.', 'comparacao_com_adversarios'],
            'urgencia' => ['Obrigada. Responda agora, por favor.', 'urgencia_artificial'],
            'intimidade' => ['Obrigada, meu amigo, pelo relato.', 'intimidade_simulada'],
            'leitura pessoal' => ['A Professora Norma leu sua mensagem e agradece.', 'alegacao_de_leitura_pessoal'],
            'dado pessoal' => ['Obrigada. Qual seu CPF para registrarmos?', 'coleta_de_dado_pessoal'],
            'vazio' => ['   ', 'texto_vazio'],
        ];
    }

    public function test_a_valid_text_passes_the_validator(): void
    {
        $result = app(ReplyTextValidator::class)->validate(
            'Obrigada por explicar. Na sua região, o problema maior e a falta de profissionais ou a distância?'
        );

        $this->assertTrue($result['valid'], implode(', ', $result['errors']));
    }

    public function test_a_generated_text_with_a_promise_is_blocked_and_never_sent(): void
    {
        $this->fakeGeneration([
            'reply_text' => 'Obrigada pelo relato. Vamos resolver esse problema na sua cidade.',
        ]);

        [, $conversation, $state] = $this->scenario();
        $suggestion = $this->generate($this->incoming($conversation));

        $this->assertSame(ReplySuggestionStatus::Blocked, $suggestion->status);
        $this->assertSame(HandoffReason::InvalidGeneratedText, $suggestion->handoff_reason);
        $this->assertStringContainsString('promessa', (string) $suggestion->validation_error);
        $this->assertSame(0, $this->outgoingCount($conversation));
        $this->assertTrue($state->refresh()->is_paused);
    }

    public function test_a_generated_text_that_is_too_long_is_blocked(): void
    {
        $this->settings(['ai.response.max_text_length' => '80']);
        $this->fakeGeneration(['reply_text' => str_repeat('texto longo ', 30)]);

        [, $conversation] = $this->scenario();
        $suggestion = $this->generate($this->incoming($conversation));

        $this->assertSame(ReplySuggestionStatus::Blocked, $suggestion->status);
        $this->assertStringContainsString('texto_muito_longo', (string) $suggestion->validation_error);
        $this->assertSame(0, $this->outgoingCount($conversation));
    }

    public function test_an_edited_text_is_also_validated_before_sending(): void
    {
        $this->fakeGeneration();
        [, $conversation] = $this->scenario();
        $suggestion = $this->generate($this->incoming($conversation));

        // Operador edita inserindo uma promessa.
        $this->actingAs($this->userWith('administrador'))
            ->post(route('admin.reply-suggestions.approve', $suggestion), [
                'final_text' => 'Obrigada. Prometo que vamos resolver.',
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, $this->outgoingCount($conversation));
    }

    // =========================================================================
    // Falha do provedor
    // =========================================================================

    public function test_a_provider_timeout_creates_no_sendable_suggestion(): void
    {
        $this->settings(['ai.max_attempts' => '1']);
        Http::fake(function (): void {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        [, $conversation, $state] = $this->scenario();
        $suggestion = $this->generate($this->incoming($conversation));

        $this->assertSame(ReplySuggestionStatus::Blocked, $suggestion->status);
        $this->assertSame(HandoffReason::RepeatedProviderFailure, $suggestion->handoff_reason);
        $this->assertSame(0, $this->outgoingCount($conversation));
        $this->assertTrue($state->refresh()->is_paused);
    }

    public function test_invalid_generated_json_creates_no_sendable_suggestion(): void
    {
        Http::fake([
            '*' => Http::response([
                'model' => 'modelo-de-teste',
                'choices' => [['message' => ['content' => 'não consegui responder']]],
            ]),
        ]);

        [, $conversation] = $this->scenario();
        $suggestion = $this->generate($this->incoming($conversation));

        $this->assertSame(ReplySuggestionStatus::Blocked, $suggestion->status);
        $this->assertSame(HandoffReason::InvalidGeneratedText, $suggestion->handoff_reason);
        $this->assertSame(0, $this->outgoingCount($conversation));
    }

    // =========================================================================
    // Debounce e feedback
    // =========================================================================

    public function test_a_newer_message_cancels_generation_for_the_older_one(): void
    {
        Http::fake();
        [, $conversation] = $this->scenario();
        $first = $this->incoming($conversation, 'Falta médico');
        $this->incoming($conversation, 'e falta também transporte até o hospital.');

        $this->assertNull($this->generate($first), 'Fragmento antigo não gera.');
        Http::assertNothingSent();
    }

    public function test_generation_is_dispatched_with_a_debounce_delay(): void
    {
        Queue::fake();
        $this->settings(['ai.response.debounce_seconds' => '30']);

        [, $conversation, $state] = $this->scenario();
        $message = $this->incoming($conversation);

        // `answer_received` e o estágio em que a 9A entrega a conversa para o
        // aprofundamento. Em `waiting_answer` com o motor tendo rodado, quem
        // acabou de falar foi a própria 9A, mandando a pergunta da pesquisa, e
        // a geração e deliberadamente pulada.
        $state->forceFill(['current_stage' => ConversationFlowStage::AnswerReceived])->save();

        event(new ConversationMessageEvaluated($message, $state, true));

        Queue::assertPushed(GenerateConversationReplyJob::class);
    }

    public function test_feedback_is_recorded_without_changing_configuration(): void
    {
        $this->fakeGeneration();
        [, $conversation] = $this->scenario();
        $suggestion = $this->generate($this->incoming($conversation));

        $settings = app(SystemSettingService::class);
        $promptBefore = $settings->get('ai.response.prompt_version');
        $thresholdBefore = $settings->get('ai.response.auto_send_min_confidence');

        $this->actingAs($this->userWith('operador'))
            ->post(route('admin.reply-suggestions.feedback', $suggestion), [
                'feedback' => SuggestionFeedback::Bad->value,
                'reason' => 'Pergunta fora do contexto.',
            ])
            ->assertSessionHas('success');

        $suggestion->refresh();
        $this->assertSame(SuggestionFeedback::Bad, $suggestion->feedback);
        $this->assertSame('Pergunta fora do contexto.', $suggestion->feedback_reason);

        // Nada muda automaticamente por causa do feedback.
        $this->assertSame($promptBefore, $settings->get('ai.response.prompt_version'));
        $this->assertSame($thresholdBefore, $settings->get('ai.response.auto_send_min_confidence'));
    }

    // =========================================================================
    // Regressão da resposta manual
    // =========================================================================

    public function test_manual_reply_still_works_exactly_as_before(): void
    {
        Queue::fake();
        [, $conversation] = $this->scenario();
        $user = $this->userWith('administrador');
        $conversation->update(['assigned_user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('admin.inbox.reply', $conversation), ['body' => 'Resposta manual de teste.'])
            ->assertRedirect();

        $message = ConversationMessage::where('conversation_id', $conversation->id)
            ->where('direction', ConversationMessageDirection::Outgoing)
            ->firstOrFail();

        $this->assertSame(ConversationMessageOrigin::Manual, $message->origin);
        $this->assertFalse((bool) $message->generated_by_ai);
        $this->assertNull($message->ai_run_id);
        $this->assertSame($user->id, $message->created_by);
        $this->assertSame(ConversationMessageStatus::Pending, $message->status);
        $this->assertNotNull($message->request_id);
        $this->assertDatabaseHas('conversation_events', ['event_type' => 'reply_requested']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'conversation.manual_reply_requested']);
    }

    public function test_manual_reply_still_refuses_a_do_not_contact_contact(): void
    {
        [$contact, $conversation] = $this->scenario();
        $contact->update(['do_not_contact' => true]);
        $user = $this->userWith('administrador');
        $conversation->update(['assigned_user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('admin.inbox.reply', $conversation), ['body' => 'Tentativa.'])
            ->assertSessionHasErrors('conversation');

        $this->assertSame(0, $this->outgoingCount($conversation));
    }
}
