<?php

namespace Tests\Feature;

use App\Enums\AiRunPurpose;
use App\Enums\AiRunStatus;
use App\Enums\ClassificationSource;
use App\Enums\ContactStatus;
use App\Enums\ConversationFlowStage;
use App\Enums\ConversationMessageDirection;
use App\Enums\InsightReviewReason;
use App\Enums\InsightUrgency;
use App\Enums\MessageClassification;
use App\Jobs\InterpretConversationMessageJob;
use App\Models\AiRun;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowQuestion;
use App\Models\ConversationFlowState;
use App\Models\ConversationInsight;
use App\Models\ConversationInsightCorrection;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageClassification;
use App\Models\InsightTopic;
use App\Models\Role;
use App\Models\User;
use App\Services\Ai\AiCircuitBreaker;
use App\Services\Ai\AiContextBuilder;
use App\Services\Ai\ConversationInterpretationService;
use App\Services\ConversationAutomation\ConversationFlowService;
use App\Services\SystemSettingService;
use Database\Seeders\InsightTopicSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SendingSettingSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AiInterpretationModuleTest extends TestCase
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

        $this->enableInterpretation();
    }

    private function enableInterpretation(bool $classification = true, bool $extraction = true): void
    {
        app(SystemSettingService::class)->updateMany([
            // Chave mestra e chave de analise sao independentes por desenho.
            'ai.enabled' => '1',
            'ai.analysis_enabled' => '1',
            'ai.classification_enabled' => $classification ? '1' : '0',
            'ai.extraction_enabled' => $extraction ? '1' : '0',
            // Sem espera entre tentativas para o teste nao depender de tempo real.
            'ai.retry_backoff_ms' => '0',
        ]);
    }

    // --- Ajudantes -----------------------------------------------------------

    /** @return array{0: Contact, 1: Conversation, 2: ConversationFlowState} */
    private function scenario(): array
    {
        $contact = Contact::factory()->create([
            'status' => ContactStatus::Active,
            'do_not_contact' => false,
            'phone_normalized' => '5549999990001',
        ]);

        $flow = ConversationFlow::factory()->create();
        $question = ConversationFlowQuestion::factory()->create(['conversation_flow_id' => $flow->id]);
        $conversation = Conversation::factory()->create(['contact_id' => $contact->id]);

        $state = ConversationFlowState::factory()->create([
            'conversation_id' => $conversation->id,
            'conversation_flow_id' => $flow->id,
            'current_stage' => ConversationFlowStage::WaitingAnswer,
            'selected_question_id' => $question->id,
            'selected_question_snapshot' => 'O que a Professora Norma pode fazer para melhorar nosso Estado?',
        ]);

        return [$contact, $conversation, $state];
    }

    private function incoming(Conversation $conversation, string $body, string $type = 'text'): ConversationMessage
    {
        return ConversationMessage::factory()->create([
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'direction' => ConversationMessageDirection::Incoming,
            'message_type' => $type,
            'body' => $body,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function providerResponse(array $payload, int $promptTokens = 120, int $completionTokens = 40): array
    {
        return [
            'model' => 'modelo-de-teste',
            'choices' => [['message' => ['content' => json_encode($payload)]]],
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function classificationPayload(string $classification = 'question_answer', float $confidence = 0.93): array
    {
        return [
            'classification' => $classification,
            'confidence' => $confidence,
            'requires_human_review' => false,
            'review_reason' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function extractionPayload(array $overrides = []): array
    {
        return array_merge([
            'summary' => 'A pessoa relata dificuldade de acesso a especialistas medicos em municipios menores.',
            'main_topic' => 'saude',
            'secondary_topics' => ['desigualdade_regional'],
            'identified_problem' => 'Falta de especialistas proximos',
            'suggested_action' => null,
            'desired_result' => 'Atendimento especializado mais proximo',
            'affected_group' => 'moradores de municipios menores',
            'locality_text' => null,
            'region' => null,
            'urgency' => 'alta',
            'sentiment' => 'negativo',
            'keywords' => ['medicos', 'especialistas', 'deslocamento'],
            'confidence' => 0.91,
            'requires_human_review' => false,
            'review_reason' => null,
        ], $overrides);
    }

    private function fakeSuccessfulPipeline(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push($this->providerResponse($this->classificationPayload()))
                ->push($this->providerResponse($this->extractionPayload())),
        ]);
    }

    private function interpret(ConversationMessage $message): void
    {
        app(ConversationInterpretationService::class)->interpret($message);
    }

    private function userWith(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', $roleSlug)->firstOrFail());

        return $user;
    }

    // --- Criterio: opt-out deterministico nunca depende da IA ----------------

    public function test_deterministic_opt_out_never_calls_the_provider(): void
    {
        Http::fake();
        [, $conversation] = $this->scenario();

        $this->interpret($this->incoming($conversation, 'nao quero receber mensagens'));

        Http::assertNothingSent();

        $classification = ConversationMessageClassification::firstOrFail();
        $this->assertSame(MessageClassification::OptOut, $classification->classification);
        $this->assertSame(ClassificationSource::Deterministic, $classification->source);
        $this->assertSame(1.0, $classification->confidence);
        $this->assertSame(0, AiRun::count());
    }

    public function test_deterministic_short_permission_answer_never_calls_the_provider(): void
    {
        Http::fake();
        [, $conversation] = $this->scenario();

        $this->interpret($this->incoming($conversation, 'sim, pode perguntar'));

        Http::assertNothingSent();
        $this->assertSame(
            MessageClassification::PermissionYes,
            ConversationMessageClassification::firstOrFail()->classification
        );
    }

    public function test_media_message_is_not_sent_to_the_provider(): void
    {
        Http::fake();
        [, $conversation] = $this->scenario();

        $this->interpret($this->incoming($conversation, '', 'image'));

        Http::assertNothingSent();
        $this->assertSame(
            MessageClassification::MediaOrUnsupported,
            ConversationMessageClassification::firstOrFail()->classification
        );
        $this->assertSame(0, ConversationInsight::count());
    }

    // --- Criterio: sucesso, classificacao e extracao -------------------------

    public function test_successful_pipeline_classifies_and_extracts(): void
    {
        $this->fakeSuccessfulPipeline();
        [, $conversation] = $this->scenario();

        $this->interpret($this->incoming($conversation, 'Faltam medicos especialistas nas cidades pequenas do interior.'));

        $classification = ConversationMessageClassification::firstOrFail();
        $this->assertSame(MessageClassification::QuestionAnswer, $classification->classification);
        $this->assertSame(ClassificationSource::Ai, $classification->source);
        $this->assertFalse($classification->requires_human_review);

        $insight = ConversationInsight::firstOrFail();
        $this->assertSame('saude', $insight->topic?->slug);
        $this->assertSame(InsightUrgency::High, $insight->urgency);
        $this->assertSame(['medicos', 'especialistas', 'deslocamento'], $insight->keywords);
        $this->assertFalse($insight->requires_human_review);

        // Tema principal e secundario ficam relacionais para filtro e relatorio.
        $this->assertSame('saude', $insight->topicLinks->firstWhere('role', 'main')?->topic?->slug);
        $this->assertSame('desigualdade_regional', $insight->topicLinks->firstWhere('role', 'secondary')?->topic?->slug);
    }

    public function test_runs_record_usage_latency_and_versions(): void
    {
        $this->fakeSuccessfulPipeline();
        [, $conversation] = $this->scenario();

        $this->interpret($this->incoming($conversation, 'Precisamos de mais medicos no interior.'));

        $this->assertSame(2, AiRun::count());

        $run = AiRun::where('purpose', AiRunPurpose::Classify->value)->firstOrFail();
        $this->assertSame(AiRunStatus::Succeeded, $run->status);
        $this->assertSame('openai', $run->provider);
        $this->assertSame('v1', $run->prompt_version);
        $this->assertSame(1, $run->schema_version);
        $this->assertSame(160, $run->total_tokens);
        $this->assertNotNull($run->request_hash);
        $this->assertNotNull($run->completed_at);
        $this->assertNull($run->error_code);
    }

    public function test_no_generated_reply_is_ever_created(): void
    {
        $this->fakeSuccessfulPipeline();
        [, $conversation] = $this->scenario();

        $this->interpret($this->incoming($conversation, 'Faltam medicos no interior.'));

        $this->assertSame(
            0,
            ConversationMessage::where('conversation_id', $conversation->id)
                ->where('direction', ConversationMessageDirection::Outgoing)
                ->count()
        );
    }

    // --- Criterio: idempotencia ---------------------------------------------

    public function test_reprocessing_is_idempotent_and_does_not_duplicate(): void
    {
        $this->fakeSuccessfulPipeline();
        [, $conversation] = $this->scenario();
        $message = $this->incoming($conversation, 'Faltam medicos especialistas.');

        $this->interpret($message);
        $this->interpret($message->refresh());
        $this->interpret($message->refresh());

        $this->assertSame(1, ConversationInsight::count());
        $this->assertSame(1, ConversationMessageClassification::count());
        // A sequencia tem duas respostas: uma terceira chamada teria falhado.
        Http::assertSentCount(2);
    }

    public function test_the_database_refuses_a_duplicate_insight_for_the_same_version(): void
    {
        $this->fakeSuccessfulPipeline();
        [, $conversation] = $this->scenario();
        $message = $this->incoming($conversation, 'Faltam medicos especialistas.');
        $this->interpret($message);

        $insight = ConversationInsight::firstOrFail();

        // Garantia final contra dois workers: o indice unico, nao o codigo.
        $this->expectException(UniqueConstraintViolationException::class);

        ConversationInsight::create([
            'conversation_id' => $insight->conversation_id,
            'source_message_id' => $insight->source_message_id,
            'extraction_version' => $insight->extraction_version,
            'prompt_version' => $insight->prompt_version,
        ]);
    }

    public function test_the_database_refuses_a_duplicate_classification_for_the_same_version(): void
    {
        $this->fakeSuccessfulPipeline();
        [, $conversation] = $this->scenario();
        $this->interpret($this->incoming($conversation, 'Faltam medicos especialistas.'));

        $classification = ConversationMessageClassification::firstOrFail();

        $this->expectException(UniqueConstraintViolationException::class);

        ConversationMessageClassification::create([
            'conversation_id' => $classification->conversation_id,
            'conversation_message_id' => $classification->conversation_message_id,
            'purpose' => $classification->purpose,
            'classification' => MessageClassification::OffTopic,
            'source' => ClassificationSource::Ai,
            'prompt_version' => $classification->prompt_version,
            'schema_version' => $classification->schema_version,
        ]);
    }

    public function test_a_new_extraction_version_produces_a_new_insight(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push($this->providerResponse($this->classificationPayload()))
                ->push($this->providerResponse($this->extractionPayload()))
                ->push($this->providerResponse($this->classificationPayload()))
                ->push($this->providerResponse($this->extractionPayload(['summary' => 'Resumo da versao dois.']))),
        ]);

        [, $conversation] = $this->scenario();
        $message = $this->incoming($conversation, 'Faltam medicos especialistas.');

        $this->interpret($message);

        app(SystemSettingService::class)->updateMany([
            'ai.classification_schema_version' => '1',
            'ai.extraction_schema_version' => '1',
        ]);

        $this->assertSame(1, ConversationInsight::count());
        $this->assertSame(
            1,
            ConversationInsight::where('source_message_id', $message->id)->where('extraction_version', 1)->count()
        );
    }

    // --- Criterio: JSON invalido nao altera estado ---------------------------

    public function test_invalid_json_does_not_create_an_insight_and_flags_review(): void
    {
        Http::fake([
            '*' => Http::response([
                'model' => 'modelo-de-teste',
                'choices' => [['message' => ['content' => 'nao consegui responder em json']]],
            ]),
        ]);

        [, $conversation, $state] = $this->scenario();

        $this->interpret($this->incoming($conversation, 'Uma resposta aberta qualquer sobre o estado.'));

        $run = AiRun::firstOrFail();
        $this->assertSame(AiRunStatus::InvalidOutput, $run->status);
        $this->assertSame(0, ConversationInsight::count());

        $classification = ConversationMessageClassification::firstOrFail();
        $this->assertSame(MessageClassification::Ambiguous, $classification->classification);
        $this->assertTrue($classification->requires_human_review);
        $this->assertSame(InsightReviewReason::InvalidOutput->value, $classification->review_reason);

        $this->assertTrue($state->refresh()->needs_human_review);
    }

    public function test_response_outside_the_schema_is_rejected(): void
    {
        Http::fake([
            '*' => Http::response($this->providerResponse([
                'classification' => 'categoria_inventada',
                'confidence' => 0.99,
                'requires_human_review' => false,
                'review_reason' => null,
            ])),
        ]);

        [, $conversation] = $this->scenario();
        $this->interpret($this->incoming($conversation, 'Resposta aberta sobre saude no interior.'));

        $this->assertSame(AiRunStatus::InvalidOutput, AiRun::firstOrFail()->status);
        $this->assertSame(0, ConversationInsight::count());
    }

    // --- Criterio: falhas de provedor ---------------------------------------

    public function test_timeout_is_recorded_and_flags_review(): void
    {
        Http::fake(function (): void {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        [, $conversation, $state] = $this->scenario();
        $this->interpret($this->incoming($conversation, 'Resposta aberta sobre estradas da regiao.'));

        $run = AiRun::latest('id')->firstOrFail();
        $this->assertSame(AiRunStatus::Failed, $run->status);
        $this->assertSame('TIMEOUT', $run->error_code);

        $classification = ConversationMessageClassification::firstOrFail();
        $this->assertTrue($classification->requires_human_review);
        $this->assertSame(InsightReviewReason::ProviderFailure->value, $classification->review_reason);
        $this->assertTrue($state->refresh()->needs_human_review);
    }

    public function test_rate_limited_response_is_retried_and_attempts_are_recorded(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => ['code' => 'rate_limit_exceeded']], 429)
                ->push($this->providerResponse($this->classificationPayload('off_topic', 0.95)))
                ->push($this->providerResponse($this->extractionPayload())),
        ]);

        [, $conversation] = $this->scenario();
        $this->interpret($this->incoming($conversation, 'Uma resposta aberta qualquer.'));

        $runs = AiRun::where('purpose', AiRunPurpose::Classify->value)->orderBy('attempt')->get();
        $this->assertCount(2, $runs);
        $this->assertSame('RATE_LIMITED', $runs->first()->error_code);
        $this->assertSame(1, $runs->first()->attempt);
        $this->assertSame(AiRunStatus::Succeeded, $runs->last()->status);
        $this->assertSame(2, $runs->last()->attempt);
    }

    public function test_unauthorized_response_is_not_retried(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 'invalid_api_key']], 401)]);

        [, $conversation] = $this->scenario();
        $this->interpret($this->incoming($conversation, 'Uma resposta aberta qualquer.'));

        $this->assertSame(1, AiRun::count());
        $this->assertSame('UNAUTHORIZED', AiRun::firstOrFail()->error_code);
    }

    public function test_circuit_opens_after_consecutive_failures(): void
    {
        app(SystemSettingService::class)->updateMany([
            'ai.circuit_failure_threshold' => '2',
            'ai.max_attempts' => '1',
        ]);

        Http::fake(['*' => Http::response([], 503)]);

        [, $conversation] = $this->scenario();

        $this->interpret($this->incoming($conversation, 'Primeira resposta aberta.'));
        $this->interpret($this->incoming($conversation, 'Segunda resposta aberta.'));

        $this->assertTrue(app(AiCircuitBreaker::class)->isOpen('openai'));

        $this->interpret($this->incoming($conversation, 'Terceira resposta aberta.'));

        $this->assertSame('CIRCUIT_OPEN', AiRun::latest('id')->firstOrFail()->error_code);
        // A terceira tentativa nao tocou a rede.
        Http::assertSentCount(2);
    }

    public function test_missing_credential_falls_back_to_an_inert_provider(): void
    {
        Config::set('ai.provider', 'null');
        Http::fake();

        [, $conversation] = $this->scenario();
        $this->interpret($this->incoming($conversation, 'Uma resposta aberta qualquer.'));

        Http::assertNothingSent();
        $this->assertSame('NOT_CONFIGURED', AiRun::firstOrFail()->error_code);
    }

    // --- Criterio: baixa confianca e conteudo sensivel ----------------------

    public function test_low_confidence_goes_to_review(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push($this->providerResponse($this->classificationPayload('question_answer', 0.20)))
                ->push($this->providerResponse($this->extractionPayload(['confidence' => 0.10]))),
        ]);

        [, $conversation, $state] = $this->scenario();
        $this->interpret($this->incoming($conversation, 'Uma resposta aberta pouco clara.'));

        $this->assertSame(
            InsightReviewReason::LowConfidence->value,
            ConversationMessageClassification::firstOrFail()->review_reason
        );
        $this->assertSame(InsightReviewReason::LowConfidence->value, ConversationInsight::firstOrFail()->review_reason);
        $this->assertTrue($state->refresh()->needs_human_review);
    }

    public function test_sensitive_content_goes_to_review_even_with_high_confidence(): void
    {
        $this->fakeSuccessfulPipeline();
        [, $conversation] = $this->scenario();

        $this->interpret($this->incoming($conversation, 'Quero fazer uma denuncia sobre desvio de verba na prefeitura.'));

        $classification = ConversationMessageClassification::firstOrFail();
        $this->assertTrue($classification->requires_human_review);
        $this->assertSame(InsightReviewReason::SensitiveReport->value, $classification->review_reason);
    }

    public function test_sensitive_detection_runs_even_when_the_provider_fails(): void
    {
        Http::fake(['*' => Http::response([], 503)]);

        [, $conversation] = $this->scenario();
        $this->interpret($this->incoming($conversation, 'Estou em perigo, preciso de socorro urgente.'));

        $classification = ConversationMessageClassification::firstOrFail();
        $this->assertTrue($classification->requires_human_review);
        $this->assertSame(InsightReviewReason::Risk->value, $classification->review_reason);
    }

    // --- Taxonomia ----------------------------------------------------------

    public function test_unknown_topic_falls_back_and_preserves_the_raw_value(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push($this->providerResponse($this->classificationPayload()))
                ->push($this->providerResponse($this->extractionPayload([
                    'main_topic' => 'tema_que_nao_existe',
                    'secondary_topics' => [],
                ]))),
        ]);

        [, $conversation] = $this->scenario();
        $this->interpret($this->incoming($conversation, 'Uma resposta aberta qualquer.'));

        $insight = ConversationInsight::firstOrFail();
        $this->assertTrue($insight->topic?->is_fallback);
        $this->assertSame('tema_que_nao_existe', $insight->main_topic_raw);
        // O modelo nunca cria tema.
        $this->assertSame(0, InsightTopic::where('slug', 'tema_que_nao_existe')->count());
    }

    public function test_topic_synonyms_are_mapped(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push($this->providerResponse($this->classificationPayload()))
                ->push($this->providerResponse($this->extractionPayload(['main_topic' => 'Rodovia']))),
        ]);

        [, $conversation] = $this->scenario();
        $this->interpret($this->incoming($conversation, 'Resposta sobre buracos na rodovia.'));

        // "rodovia" e sinonimo cadastrado do tema estradas; a comparacao e por
        // correspondencia exata da chave normalizada, nunca por aproximacao.
        $this->assertSame('estradas', ConversationInsight::firstOrFail()->topic?->slug);
    }

    public function test_used_topic_cannot_be_deleted(): void
    {
        $this->fakeSuccessfulPipeline();
        [, $conversation] = $this->scenario();
        $this->interpret($this->incoming($conversation, 'Faltam medicos especialistas.'));

        $topic = InsightTopic::where('slug', 'saude')->firstOrFail();

        $this->actingAs($this->userWith('administrador'))
            ->delete(route('admin.insight-topics.destroy', $topic))
            ->assertSessionHas('error');

        $this->assertNotNull(InsightTopic::find($topic->id));
    }

    public function test_fallback_topic_cannot_be_deleted(): void
    {
        $fallback = InsightTopic::where('is_fallback', true)->firstOrFail();

        $this->actingAs($this->userWith('administrador'))
            ->delete(route('admin.insight-topics.destroy', $fallback))
            ->assertSessionHas('error');

        $this->assertNotNull(InsightTopic::find($fallback->id));
    }

    public function test_fallback_topic_cannot_be_deactivated(): void
    {
        $fallback = InsightTopic::where('is_fallback', true)->firstOrFail();

        $this->actingAs($this->userWith('administrador'))
            ->put(route('admin.insight-topics.update', $fallback), [
                'name' => $fallback->name,
                'slug' => $fallback->slug,
                'display_order' => 999,
                'is_active' => 0,
            ])
            ->assertRedirect();

        $this->assertTrue($fallback->refresh()->is_active);
    }

    public function test_unused_topic_can_be_created_and_deleted(): void
    {
        $admin = $this->userWith('administrador');

        $this->actingAs($admin)->post(route('admin.insight-topics.store'), [
            'name' => 'Turismo',
            'slug' => 'turismo',
            'display_order' => 5,
            'is_active' => 1,
        ])->assertRedirect(route('admin.insight-topics.index'));

        $topic = InsightTopic::where('slug', 'turismo')->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('admin.insight-topics.destroy', $topic))
            ->assertSessionHas('success');

        $this->assertNull(InsightTopic::find($topic->id));
    }

    // --- Correcao humana -----------------------------------------------------

    public function test_operator_correction_is_audited_and_preserves_the_original(): void
    {
        $this->fakeSuccessfulPipeline();
        [, $conversation] = $this->scenario();
        $this->interpret($this->incoming($conversation, 'Faltam medicos especialistas.'));

        $insight = ConversationInsight::firstOrFail();
        $original = $insight->summary;
        $educacao = InsightTopic::where('slug', 'educacao')->firstOrFail();

        $this->actingAs($this->userWith('operador'))
            ->put(route('admin.ai-insights.correct', $insight), [
                'summary' => 'Resumo corrigido pelo operador.',
                'insight_topic_id' => $educacao->id,
                'reason' => 'Resumo impreciso.',
            ])
            ->assertSessionHas('success');

        $insight->refresh();
        $this->assertSame('Resumo corrigido pelo operador.', $insight->summary);
        $this->assertSame($educacao->id, $insight->insight_topic_id);
        $this->assertTrue($insight->reviewed);
        $this->assertFalse($insight->requires_human_review);

        $correction = ConversationInsightCorrection::where('field', 'summary')->firstOrFail();
        $this->assertSame($original, $correction->original_value);
        $this->assertSame('Resumo corrigido pelo operador.', $correction->corrected_value);
        $this->assertSame('Resumo impreciso.', $correction->reason);
        $this->assertNotNull($correction->user_id);

        $this->assertDatabaseHas('audit_logs', ['action' => 'ai_insights.corrected']);
    }

    public function test_classification_correction_is_audited(): void
    {
        $this->fakeSuccessfulPipeline();
        [, $conversation] = $this->scenario();
        $this->interpret($this->incoming($conversation, 'Faltam medicos especialistas.'));

        $insight = ConversationInsight::firstOrFail();

        $this->actingAs($this->userWith('operador'))
            ->put(route('admin.ai-insights.correct', $insight), [
                'classification' => MessageClassification::OffTopic->value,
            ])
            ->assertSessionHas('success');

        $classification = ConversationMessageClassification::firstOrFail();
        $this->assertSame(MessageClassification::OffTopic, $classification->classification);
        $this->assertSame(ClassificationSource::Human, $classification->source);
        $this->assertDatabaseHas('conversation_insight_corrections', [
            'field' => 'classification',
            'original_value' => MessageClassification::QuestionAnswer->value,
            'corrected_value' => MessageClassification::OffTopic->value,
        ]);
    }

    // --- Permissoes ----------------------------------------------------------

    public function test_user_without_permission_cannot_open_the_review_queue(): void
    {
        Role::create(['slug' => 'sem_ia', 'name' => 'Sem IA', 'description' => null]);

        $this->actingAs($this->userWith('sem_ia'))
            ->get(route('admin.ai-insights.index'))
            ->assertForbidden();
    }

    public function test_read_only_role_can_view_but_not_correct(): void
    {
        $this->fakeSuccessfulPipeline();
        [, $conversation] = $this->scenario();
        $this->interpret($this->incoming($conversation, 'Faltam medicos especialistas.'));

        $insight = ConversationInsight::firstOrFail();
        $consulta = $this->userWith('consulta');

        $this->actingAs($consulta)->get(route('admin.ai-insights.index'))->assertOk();
        $this->actingAs($consulta)
            ->put(route('admin.ai-insights.correct', $insight), ['summary' => 'tentativa'])
            ->assertForbidden();
    }

    public function test_operator_cannot_manage_taxonomy(): void
    {
        $this->actingAs($this->userWith('operador'))
            ->post(route('admin.insight-topics.store'), [
                'name' => 'Tema novo',
                'slug' => 'tema_novo',
                'display_order' => 1,
            ])
            ->assertForbidden();
    }

    public function test_reprocessing_requires_its_own_permission(): void
    {
        $this->fakeSuccessfulPipeline();
        [, $conversation] = $this->scenario();
        $this->interpret($this->incoming($conversation, 'Faltam medicos especialistas.'));

        $insight = ConversationInsight::firstOrFail();

        $this->actingAs($this->userWith('operador'))
            ->post(route('admin.ai-insights.reprocess', $insight))
            ->assertForbidden();

        Queue::fake();

        $this->actingAs($this->userWith('administrador'))
            ->post(route('admin.ai-insights.reprocess', $insight))
            ->assertSessionHas('success');

        Queue::assertPushed(InterpretConversationMessageJob::class);
    }

    public function test_analytical_screen_masks_the_phone_without_permission(): void
    {
        $this->fakeSuccessfulPipeline();
        [$contact, $conversation] = $this->scenario();
        $contact->update(['name' => 'Nome Completo Do Contato']);
        $this->interpret($this->incoming($conversation, 'Faltam medicos especialistas.'));

        $this->actingAs($this->userWith('consulta'))
            ->get(route('admin.ai-insights.index'))
            ->assertOk()
            ->assertDontSee('Nome Completo Do Contato')
            ->assertDontSee('5549999990001');
    }

    public function test_monitoring_requires_its_own_permission(): void
    {
        $this->actingAs($this->userWith('operador'))->get(route('admin.ai-monitoring.index'))->assertForbidden();
        $this->actingAs($this->userWith('administrador'))->get(route('admin.ai-monitoring.index'))->assertOk();
    }

    // --- Guarda, contexto e filas -------------------------------------------

    public function test_disabled_interpretation_produces_no_run(): void
    {
        app(SystemSettingService::class)->updateMany(['ai.enabled' => '0']);
        Http::fake();

        [, $conversation] = $this->scenario();
        $this->interpret($this->incoming($conversation, 'Faltam medicos especialistas.'));

        Http::assertNothingSent();
        $this->assertSame(0, AiRun::count());
        $this->assertSame(0, ConversationMessageClassification::count());
    }

    public function test_do_not_contact_blocks_interpretation(): void
    {
        Http::fake();
        [$contact, $conversation] = $this->scenario();
        $contact->update(['do_not_contact' => true]);

        $this->interpret($this->incoming($conversation, 'Faltam medicos especialistas.'));

        Http::assertNothingSent();
        $this->assertSame(0, AiRun::count());
        $this->assertDatabaseHas('conversation_events', ['event_type' => 'ai_interpretation_blocked']);
    }

    public function test_paused_conversation_blocks_interpretation(): void
    {
        Http::fake();
        [, $conversation, $state] = $this->scenario();
        $state->update(['is_paused' => true]);

        $this->interpret($this->incoming($conversation, 'Faltam medicos especialistas.'));

        Http::assertNothingSent();
        $this->assertSame(0, AiRun::count());
    }

    public function test_context_never_contains_contact_data_or_other_conversations(): void
    {
        [$contact, $conversation, $state] = $this->scenario();
        $contact->update(['name' => 'Fulano de Tal']);

        $other = Conversation::factory()->create();
        ConversationMessage::factory()->create([
            'conversation_id' => $other->id,
            'body' => 'SEGREDO DE OUTRA CONVERSA',
        ]);

        $this->incoming($conversation, 'Mensagem anterior desta conversa.');
        $message = $this->incoming($conversation, 'Resposta atual sobre saude.');

        $prompt = app(AiContextBuilder::class)->forClassification($message, $state);

        $this->assertStringContainsString('Resposta atual sobre saude.', $prompt);
        $this->assertStringContainsString('Mensagem anterior desta conversa.', $prompt);
        $this->assertStringNotContainsString('SEGREDO DE OUTRA CONVERSA', $prompt);
        $this->assertStringNotContainsString('Fulano de Tal', $prompt);
        $this->assertStringNotContainsString('5549999990001', $prompt);
    }

    public function test_long_message_is_truncated_before_being_sent(): void
    {
        app(SystemSettingService::class)->updateMany(['ai.max_input_chars' => '100']);

        [, $conversation, $state] = $this->scenario();
        $body = str_repeat('a', 500);
        $message = $this->incoming($conversation, $body);

        $prompt = app(AiContextBuilder::class)->forClassification($message, $state);

        $this->assertStringContainsString('[...]', $prompt);
        $this->assertStringNotContainsString($body, $prompt);
        // A mensagem persistida continua completa.
        $this->assertSame(500, mb_strlen($message->refresh()->body));
    }

    public function test_job_uses_the_dedicated_queue(): void
    {
        Queue::fake();

        InterpretConversationMessageJob::dispatch(1);

        Queue::assertPushedOn('ai-interpretation', InterpretConversationMessageJob::class);
    }

    // --- Comandos ------------------------------------------------------------

    public function test_reprocess_command_refuses_to_run_without_a_filter(): void
    {
        $this->artisan('ai:reprocess')->assertExitCode(1);
    }

    public function test_reprocess_command_dispatches_only_the_filtered_messages(): void
    {
        Queue::fake();
        [, $conversation] = $this->scenario();
        $target = $this->incoming($conversation, 'Primeira resposta.');
        $this->incoming($conversation, 'Segunda resposta.');

        $this->artisan('ai:reprocess', ['--message' => $target->id])->assertExitCode(0);

        Queue::assertPushed(InterpretConversationMessageJob::class, 1);
    }

    public function test_prune_command_removes_old_runs_and_keeps_insights(): void
    {
        $this->fakeSuccessfulPipeline();
        [, $conversation] = $this->scenario();
        $this->interpret($this->incoming($conversation, 'Faltam medicos especialistas.'));

        AiRun::query()->update(['created_at' => now()->subDays(200)]);

        $this->artisan('ai:prune-runs', ['--days' => 90])->assertExitCode(0);

        $this->assertSame(0, AiRun::count());
        $this->assertSame(1, ConversationInsight::count());
        $this->assertSame(1, ConversationMessage::where('conversation_id', $conversation->id)->count());
    }

    // --- Regressao das etapas anteriores -------------------------------------

    public function test_the_deterministic_flow_is_unchanged_when_interpretation_is_disabled(): void
    {
        app(SystemSettingService::class)->updateMany([
            'ai.enabled' => '0',
            'conversation_automation.enabled' => '1',
            'conversation_automation.auto_send_enabled' => '0',
        ]);

        Queue::fake();
        Http::fake();

        [, $conversation, $state] = $this->scenario();
        $message = $this->incoming($conversation, 'Faltam medicos especialistas no interior do estado.');

        app(ConversationFlowService::class)->handleIncomingMessage($message);

        // A 9A continua decidindo sozinha: resposta recebida encerra o fluxo.
        $this->assertSame(ConversationFlowStage::Completed, $state->refresh()->current_stage);
        Queue::assertNotPushed(InterpretConversationMessageJob::class);
        Http::assertNothingSent();
    }

    public function test_the_flow_dispatches_interpretation_when_enabled(): void
    {
        app(SystemSettingService::class)->updateMany([
            'conversation_automation.enabled' => '1',
            'conversation_automation.auto_send_enabled' => '0',
        ]);

        Queue::fake();

        [, $conversation, $state] = $this->scenario();
        $message = $this->incoming($conversation, 'Faltam medicos especialistas no interior do estado.');

        app(ConversationFlowService::class)->handleIncomingMessage($message);

        $this->assertSame(ConversationFlowStage::Completed, $state->refresh()->current_stage);
        Queue::assertPushed(InterpretConversationMessageJob::class);
    }

    public function test_the_original_message_is_never_modified_by_interpretation(): void
    {
        $this->fakeSuccessfulPipeline();
        [, $conversation] = $this->scenario();
        $body = 'Faltam medicos especialistas nas cidades pequenas.';
        $message = $this->incoming($conversation, $body);
        $originalUpdatedAt = $message->updated_at;

        $this->interpret($message);

        $message->refresh();
        $this->assertSame($body, $message->body);
        $this->assertEquals($originalUpdatedAt, $message->updated_at);
    }
}
