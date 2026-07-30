<?php

namespace Tests\Feature;

use App\Enums\AiRunPurpose;
use App\Enums\AiRunStatus;
use App\Enums\ClassificationSource;
use App\Enums\ContactStatus;
use App\Enums\ConversationFlowStage;
use App\Enums\ConversationMessageDirection;
use App\Enums\MessageClassification;
use App\Enums\PermissionResponseClassification;
use App\Jobs\InterpretConversationMessageJob;
use App\Jobs\SendAutomatedConversationReplyJob;
use App\Jobs\SendManualConversationReplyJob;
use App\Models\AiRun;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowQuestion;
use App\Models\ConversationFlowState;
use App\Models\ConversationInsight;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageClassification;
use App\Services\Ai\AiContextBuilder;
use App\Services\Ai\AiInterpretationGuard;
use App\Services\Ai\AiSchemaRegistry;
use App\Services\Ai\ConversationInterpretationService;
use App\Services\ConversationAutomation\ConversationFlowService;
use App\Services\ConversationAutomation\PermissionResponseClassifier;
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
 * Testes da etapa de revisão e estabilização das subetapas 9A e 9B.
 *
 * Cobrem separação de feature flags, precedência determinística sobre as listas
 * reais de produção, ausência de campos sensíveis nos schemas, isolamento de
 * contexto, matriz de falhas do provedor e a garantia de que a 9B nunca envia.
 */
class AiInterpretationHardeningTest extends TestCase
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
    }

    // --- Ajudantes -----------------------------------------------------------

    /** @param array<string, string> $extra */
    private function settings(array $extra = []): void
    {
        app(SystemSettingService::class)->updateMany(array_merge([
            'ai.retry_backoff_ms' => '0',
        ], $extra));
    }

    private function enableAnalysis(): void
    {
        $this->settings(['ai.enabled' => '1', 'ai.analysis_enabled' => '1']);
    }

    /** @return array{0: Contact, 1: Conversation, 2: ConversationFlowState} */
    private function scenario(string $phone = '5549999990001'): array
    {
        $contact = Contact::factory()->create([
            'status' => ContactStatus::Active,
            'do_not_contact' => false,
            'phone_normalized' => $phone,
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
    private function response(array $payload): array
    {
        return [
            'model' => 'modelo-de-teste',
            'choices' => [['message' => ['content' => json_encode($payload)]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];
    }

    /** @return array<string, mixed> */
    private function extractionPayload(): array
    {
        return [
            'summary' => 'Resumo objetivo do relato.',
            'main_topic' => 'saude',
            'secondary_topics' => [],
            'identified_problem' => 'Problema relatado',
            'suggested_action' => null,
            'desired_result' => null,
            'affected_group' => null,
            'locality_text' => null,
            'region' => null,
            'urgency' => 'media',
            'sentiment' => 'neutro',
            'keywords' => ['saude'],
            'confidence' => 0.9,
            'requires_human_review' => false,
            'review_reason' => null,
        ];
    }

    private function interpret(ConversationMessage $message): void
    {
        app(ConversationInterpretationService::class)->interpret($message);
    }

    // =========================================================================
    // 2. SEPARAÇÃO DE FEATURE FLAGS
    // =========================================================================

    public function test_new_flags_are_disabled_by_default(): void
    {
        $guard = app(AiInterpretationGuard::class);

        $this->assertFalse($guard->enabled(), 'ai.enabled deve vir desligada.');
        $this->assertFalse($guard->analysisEnabled(), 'ai.analysis_enabled deve vir desligada.');
        $this->assertFalse($guard->responseGenerationEnabled(), 'ai.response_generation_enabled e reservada para a 9C.');
        $this->assertFalse($guard->autoSendEnabled(), 'ai.auto_send_enabled e reservada para a 9C.');
    }

    public function test_reserved_9c_flags_stay_off_even_when_explicitly_turned_on_without_the_master_key(): void
    {
        $this->settings([
            'ai.enabled' => '0',
            'ai.response_generation_enabled' => '1',
            'ai.auto_send_enabled' => '1',
        ]);

        $guard = app(AiInterpretationGuard::class);

        $this->assertFalse($guard->responseGenerationEnabled());
        $this->assertFalse($guard->autoSendEnabled());
    }

    public function test_master_key_alone_does_not_enable_analysis(): void
    {
        $this->settings(['ai.enabled' => '1', 'ai.analysis_enabled' => '0']);
        Http::fake();

        [, $conversation] = $this->scenario();
        $this->interpret($this->incoming($conversation, 'Uma resposta aberta sobre saúde.'));

        Http::assertNothingSent();
        $this->assertSame(0, AiRun::count());
        $this->assertDatabaseHas('conversation_events', ['event_type' => 'ai_interpretation_blocked']);
    }

    public function test_analysis_runs_even_with_the_9a_engine_disabled(): void
    {
        // O ponto central da separação: a 9B não depende da chave da 9A.
        $this->settings([
            'conversation_automation.enabled' => '0',
            'ai.enabled' => '1',
            'ai.analysis_enabled' => '1',
        ]);

        Queue::fake();
        [, $conversation] = $this->scenario();
        $message = $this->incoming($conversation, 'Faltam médicos especialistas no interior.');

        app(ConversationFlowService::class)->handleIncomingMessage($message);

        Queue::assertPushed(InterpretConversationMessageJob::class);
    }

    public function test_the_9a_engine_runs_with_analysis_disabled(): void
    {
        $this->settings([
            'conversation_automation.enabled' => '1',
            'conversation_automation.auto_send_enabled' => '0',
            'ai.enabled' => '0',
            'ai.analysis_enabled' => '0',
        ]);

        Queue::fake();
        Http::fake();

        [, $conversation, $state] = $this->scenario();
        $message = $this->incoming($conversation, 'Faltam médicos especialistas no interior.');

        app(ConversationFlowService::class)->handleIncomingMessage($message);

        $this->assertSame(ConversationFlowStage::Completed, $state->refresh()->current_stage);
        Queue::assertNotPushed(InterpretConversationMessageJob::class);
        Http::assertNothingSent();
    }

    public function test_interpretation_requires_a_survey_context(): void
    {
        $this->enableAnalysis();
        Http::fake();

        // Conversa sem estado de fluxo: não e uma resposta de pesquisa.
        $conversation = Conversation::factory()->create();
        $this->interpret($this->incoming($conversation, 'Uma mensagem avulsa qualquer.'));

        Http::assertNothingSent();
        $this->assertSame(0, AiRun::count());
        $this->assertDatabaseHas('conversation_events', [
            'event_type' => 'ai_interpretation_blocked',
        ]);
    }

    public function test_the_flow_service_does_not_reference_the_ai_layer(): void
    {
        // Garantia de separação entre as subetapas: a 9A não conhece a 9B.
        $source = file_get_contents(app_path('Services/ConversationAutomation/ConversationFlowService.php'));

        $this->assertStringNotContainsString('App\\Services\\Ai', $source);
        $this->assertStringNotContainsString('InterpretConversationMessageJob', $source);
        $this->assertStringContainsString('ConversationMessageEvaluated', $source);
    }

    // =========================================================================
    // 3. PRECEDÊNCIA DETERMINÍSTICA
    // =========================================================================

    #[DataProvider('deterministicPhrases')]
    public function test_deterministic_precedence_over_the_production_expression_lists(
        string $phrase,
        PermissionResponseClassification $expected
    ): void {
        $result = app(PermissionResponseClassifier::class)->classify($phrase);

        $this->assertSame(
            $expected,
            $result['classification'],
            "Frase \"{$phrase}\" classificada como {$result['classification']->value} (motivo: {$result['reason']})."
        );
    }

    /** @return array<string, array{0: string, 1: PermissionResponseClassification}> */
    public static function deterministicPhrases(): array
    {
        $yes = PermissionResponseClassification::PermissionYes;
        $no = PermissionResponseClassification::PermissionNo;
        $out = PermissionResponseClassification::OptOut;

        return [
            'sim' => ['sim', $yes],
            'pode' => ['pode', $yes],
            'claro' => ['claro', $yes],
            'sim, pode perguntar' => ['sim, pode perguntar', $yes],
            'nao' => ['não', $no],
            'agora não' => ['agora não', $no],
            'não quero' => ['não quero', $no],
            'não tenho interesse' => ['não tenho interesse', $no],
            'pare' => ['pare', $out],
            'parar' => ['parar', $out],
            'retire meu número' => ['retire meu número', $out],
            'remova meu contato' => ['remova meu contato', $out],
            'não me mande mais mensagens' => ['não me mande mais mensagens', $out],
            // Conflitantes: o pedido inequivoco de interrupção prevalece.
            'positiva com pedido de interrupção' => ['sim, mas não quero receber mais mensagens', $out],
            'consentimento com interrupção futura' => ['pode perguntar, mas depois não me mande mais mensagens', $out],
        ];
    }

    public function test_a_report_of_wrongdoing_is_not_treated_as_opt_out(): void
    {
        // Regressão: "denuncia" estava na lista de opt-out e marcava o contato
        // como não contatar, interrompendo lotes indevidamente.
        $result = app(PermissionResponseClassifier::class)->classify('Quero fazer uma denuncia sobre desvio de verba.');

        $this->assertNotSame(PermissionResponseClassification::OptOut, $result['classification']);
    }

    #[DataProvider('deterministicPhrases')]
    public function test_deterministic_decisions_never_reach_the_provider(string $phrase): void
    {
        $this->enableAnalysis();
        Http::fake();

        [, $conversation] = $this->scenario();
        $this->interpret($this->incoming($conversation, $phrase));

        Http::assertNothingSent();
        $this->assertSame(0, AiRun::count(), 'Uma decisão determinística não pode gastar tokens.');

        $classification = ConversationMessageClassification::firstOrFail();
        $this->assertSame(ClassificationSource::Deterministic, $classification->source);
        $this->assertSame(1.0, $classification->confidence);
        $this->assertSame('deterministic', $classification->prompt_version);
    }

    // =========================================================================
    // 4. IDEMPOTÊNCIA E CONCORRÊNCIA
    // =========================================================================

    public function test_a_provider_retry_creates_a_new_run_and_preserves_the_previous_attempt(): void
    {
        $this->enableAnalysis();

        Http::fake([
            '*' => Http::sequence()
                ->push([], 500)
                ->push($this->response([
                    'classification' => 'question_answer',
                    'confidence' => 0.9,
                    'requires_human_review' => false,
                    'review_reason' => null,
                ]))
                ->push($this->response($this->extractionPayload())),
        ]);

        [, $conversation] = $this->scenario();
        $this->interpret($this->incoming($conversation, 'Uma resposta aberta sobre saúde no interior.'));

        $runs = AiRun::where('purpose', AiRunPurpose::Classify->value)->orderBy('attempt')->get();

        $this->assertCount(2, $runs, 'Cada tentativa gera um ai_run próprio.');
        $this->assertSame(AiRunStatus::Failed, $runs->first()->status, 'A tentativa que falhou continua preservada.');
        $this->assertSame(AiRunStatus::Succeeded, $runs->last()->status);
        $this->assertSame(1, ConversationInsight::count(), 'Uma única extração valida.');
    }

    public function test_simulated_concurrent_workers_produce_a_single_insight(): void
    {
        $this->enableAnalysis();

        // Duas execuções com a mesma versão: a segunda não deve chamar o
        // provedor nem criar um segundo resultado corrente.
        Http::fake([
            '*' => Http::sequence()
                ->push($this->response([
                    'classification' => 'question_answer',
                    'confidence' => 0.9,
                    'requires_human_review' => false,
                    'review_reason' => null,
                ]))
                ->push($this->response($this->extractionPayload())),
        ]);

        [, $conversation] = $this->scenario();
        $message = $this->incoming($conversation, 'Uma resposta aberta sobre saúde no interior.');

        $this->interpret($message);
        $this->interpret($message->refresh());

        Http::assertSentCount(2);
        $this->assertSame(1, ConversationInsight::count());
        $this->assertSame(1, ConversationMessageClassification::count());

        $current = ConversationInsight::query()
            ->where('source_message_id', $message->id)
            ->where('extraction_version', app(AiSchemaRegistry::class)->activeVersion(AiRunPurpose::ExtractInsight))
            ->count();

        $this->assertSame(1, $current, 'Somente um resultado corrente por mensagem e versão.');
    }

    // =========================================================================
    // 5. SCHEMA E DADOS SENSÍVEIS
    // =========================================================================

    #[DataProvider('aiPurposes')]
    public function test_schemas_have_no_sensitive_attribute_fields(AiRunPurpose $purpose): void
    {
        $schema = app(AiSchemaRegistry::class)->get($purpose);
        $fields = array_keys($schema['properties']);
        $blob = mb_strtolower(implode(' ', $fields));

        foreach ([
            'voto', 'vote', 'voting', 'eleitor', 'election', 'partido', 'party',
            'political', 'politica', 'ideolog', 'religi', 'raca', 'race', 'etnia',
            'ethnic', 'renda', 'income', 'salario', 'salary', 'orientacao',
            'orientation', 'sexual', 'gender', 'genero', 'saude', 'health',
            'medical', 'diagnos', 'cpf', 'documento',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $blob,
                "O schema {$purpose->value} não pode ter campo relacionado a \"{$forbidden}\"."
            );
        }
    }

    #[DataProvider('aiPurposes')]
    public function test_schemas_forbid_additional_properties(AiRunPurpose $purpose): void
    {
        $schema = app(AiSchemaRegistry::class)->get($purpose);

        $this->assertFalse($schema['additionalProperties'], "O schema {$purpose->value} deve recusar campos extras.");
    }

    /** @return array<string, array{0: AiRunPurpose}> */
    public static function aiPurposes(): array
    {
        return [
            'classificacao' => [AiRunPurpose::Classify],
            'extracao' => [AiRunPurpose::ExtractInsight],
        ];
    }

    public function test_extra_sensitive_properties_from_the_provider_are_rejected(): void
    {
        $this->enableAnalysis();

        Http::fake([
            '*' => Http::response($this->response([
                'classification' => 'question_answer',
                'confidence' => 0.95,
                'requires_human_review' => false,
                'review_reason' => null,
                // O modelo tenta anexar atributos que o sistema não deve possuir.
                'voting_intention' => 'partido x',
                'religion' => 'catolica',
                'income_bracket' => 'alta',
            ])),
        ]);

        [, $conversation] = $this->scenario();
        $this->interpret($this->incoming($conversation, 'Uma resposta aberta sobre saúde.'));

        $run = AiRun::firstOrFail();
        $this->assertSame(AiRunStatus::InvalidOutput, $run->status);
        $this->assertNull($run->result, 'Saída rejeitada não pode ser persistida.');
        $this->assertSame(0, ConversationInsight::count());

        // Nenhuma execução pode ter sido aceita, e o valor sensível não pode
        // vazar nem para a mensagem de erro.
        $this->assertSame(0, AiRun::where('status', AiRunStatus::Succeeded->value)->count());
        $this->assertStringNotContainsString('partido x', (string) $run->error_message);
        $this->assertStringNotContainsString('catolica', (string) $run->error_message);
    }

    // =========================================================================
    // 6. ISOLAMENTO DE CONTEXTO
    // =========================================================================

    public function test_context_is_isolated_between_two_conversations(): void
    {
        [$contactA, $conversationA, $stateA] = $this->scenario('5549999990001');
        [$contactB, $conversationB] = $this->scenario('5549999990002');

        $contactA->update(['name' => 'Alice Primeira', 'email' => 'alice@example.com', 'city' => 'Chapeco']);
        $contactB->update(['name' => 'Bruno Segundo']);

        $this->incoming($conversationB, 'CONTEÚDO EXCLUSIVO DA CONVERSA B');
        $this->incoming($conversationA, 'Mensagem anterior da conversa A.');
        $messageA = $this->incoming($conversationA, 'Resposta atual da conversa A.');

        $builder = app(AiContextBuilder::class);

        foreach ([
            $builder->forClassification($messageA, $stateA),
            $builder->forExtraction($messageA, $stateA, ['saude'], 'outros'),
        ] as $prompt) {
            $this->assertStringContainsString('Resposta atual da conversa A.', $prompt);
            $this->assertStringContainsString('Mensagem anterior da conversa A.', $prompt);

            $this->assertStringNotContainsString('CONTEÚDO EXCLUSIVO DA CONVERSA B', $prompt);
            $this->assertStringNotContainsString('Alice Primeira', $prompt);
            $this->assertStringNotContainsString('Bruno Segundo', $prompt);
            $this->assertStringNotContainsString('alice@example.com', $prompt);
            $this->assertStringNotContainsString('Chapeco', $prompt);
            $this->assertStringNotContainsString('5549999990001', $prompt);
            $this->assertStringNotContainsString('5549999990002', $prompt);
        }
    }

    public function test_the_request_actually_sent_to_the_provider_carries_no_personal_data(): void
    {
        $this->enableAnalysis();

        Http::fake([
            '*' => Http::sequence()
                ->push($this->response([
                    'classification' => 'question_answer',
                    'confidence' => 0.9,
                    'requires_human_review' => false,
                    'review_reason' => null,
                ]))
                ->push($this->response($this->extractionPayload())),
        ]);

        [$contact, $conversation] = $this->scenario('5549988887777');
        $contact->update(['name' => 'Carlos Terceiro', 'email' => 'carlos@example.com']);

        $other = Conversation::factory()->create();
        $this->incoming($other, 'MENSAGEM DE TERCEIRO NÃO RELACIONADA');

        $this->interpret($this->incoming($conversation, 'Precisamos de mais médicos no interior.'));

        Http::assertSent(function ($request): bool {
            $body = (string) $request->body();

            $this->assertStringNotContainsString('Carlos Terceiro', $body);
            $this->assertStringNotContainsString('carlos@example.com', $body);
            $this->assertStringNotContainsString('5549988887777', $body);
            $this->assertStringNotContainsString('MENSAGEM DE TERCEIRO NÃO RELACIONADA', $body);

            return true;
        });
    }

    // =========================================================================
    // 9. MATRIZ DE FALHAS DO PROVEDOR
    // =========================================================================

    #[DataProvider('providerFailures')]
    public function test_provider_failures_never_corrupt_state(callable $fake, string $expectedErrorCode, string $expectedStatus): void
    {
        $this->enableAnalysis();
        $this->settings(['ai.max_attempts' => '1']);
        $fake();

        Queue::fake();
        [, $conversation, $state] = $this->scenario();
        $stageBefore = $state->current_stage;

        $this->interpret($this->incoming($conversation, 'Uma resposta aberta sobre saúde no interior.'));

        $run = AiRun::latest('id')->firstOrFail();
        $this->assertSame($expectedStatus, $run->status->value);
        $this->assertSame($expectedErrorCode, (string) $run->error_code);

        // Nenhuma falha pode produzir envio, avanco de fluxo ou insight.
        $this->assertSame(0, ConversationInsight::count());
        $this->assertSame(
            0,
            ConversationMessage::where('conversation_id', $conversation->id)
                ->where('direction', ConversationMessageDirection::Outgoing)
                ->count()
        );
        $this->assertSame($stageBefore, $state->refresh()->current_stage);
        Queue::assertNotPushed(SendAutomatedConversationReplyJob::class);
        Queue::assertNotPushed(SendManualConversationReplyJob::class);

        // O item foi para revisão humana em vez de seguir sozinho.
        $this->assertTrue(ConversationMessageClassification::firstOrFail()->requires_human_review);
    }

    /** @return array<string, array{0: callable, 1: string, 2: string}> */
    public static function providerFailures(): array
    {
        $ok = fn (array $payload): array => [
            'model' => 'modelo-de-teste',
            'choices' => [['message' => ['content' => json_encode($payload)]]],
        ];

        return [
            'http 400' => [fn () => Http::fake(['*' => Http::response(['error' => ['code' => 'bad_request']], 400)]), 'BAD_REQUEST', 'failed'],
            'http 404' => [fn () => Http::fake(['*' => Http::response([], 404)]), 'BAD_REQUEST', 'failed'],
            'http 422' => [fn () => Http::fake(['*' => Http::response([], 422)]), 'BAD_REQUEST', 'failed'],
            'http 401' => [fn () => Http::fake(['*' => Http::response(['error' => ['code' => 'invalid_api_key']], 401)]), 'UNAUTHORIZED', 'failed'],
            'http 403' => [fn () => Http::fake(['*' => Http::response([], 403)]), 'UNAUTHORIZED', 'failed'],
            'http 429' => [fn () => Http::fake(['*' => Http::response([], 429)]), 'RATE_LIMITED', 'failed'],
            'http 500' => [fn () => Http::fake(['*' => Http::response([], 500)]), 'SERVICE_UNAVAILABLE', 'failed'],
            'http 503' => [fn () => Http::fake(['*' => Http::response([], 503)]), 'SERVICE_UNAVAILABLE', 'failed'],
            'timeout' => [fn () => Http::fake(function (): void {
                throw new ConnectionException('cURL error 28: Operation timed out after 30000 milliseconds');
            }), 'TIMEOUT', 'failed'],
            'conexão indisponível' => [fn () => Http::fake(function (): void {
                throw new ConnectionException('cURL error 7: Failed to connect to host');
            }), 'SERVICE_UNAVAILABLE', 'failed'],
            'corpo vazio' => [fn () => Http::fake(['*' => Http::response([], 200)]), 'INVALID_RESPONSE', 'failed'],
            'conteúdo vazio' => [fn () => Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '   ']]]], 200)]), 'INVALID_RESPONSE', 'failed'],
            'json inválido' => [fn () => Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'não e json']]]], 200)]), 'INVALID_RESPONSE', 'invalid_output'],
            'classificação desconhecida' => [fn () => Http::fake(['*' => Http::response($ok([
                'classification' => 'categoria_inexistente', 'confidence' => 0.9, 'requires_human_review' => false, 'review_reason' => null,
            ]))]), 'INVALID_RESPONSE', 'invalid_output'],
            'confiança acima de um' => [fn () => Http::fake(['*' => Http::response($ok([
                'classification' => 'question_answer', 'confidence' => 7.5, 'requires_human_review' => false, 'review_reason' => null,
            ]))]), 'INVALID_RESPONSE', 'invalid_output'],
            'confiança como texto' => [fn () => Http::fake(['*' => Http::response($ok([
                'classification' => 'question_answer', 'confidence' => 'alta', 'requires_human_review' => false, 'review_reason' => null,
            ]))]), 'INVALID_RESPONSE', 'invalid_output'],
            'campo obrigatório ausente' => [fn () => Http::fake(['*' => Http::response($ok([
                'classification' => 'question_answer', 'confidence' => 0.9,
            ]))]), 'INVALID_RESPONSE', 'invalid_output'],
            'propriedades extras' => [fn () => Http::fake(['*' => Http::response($ok([
                'classification' => 'question_answer', 'confidence' => 0.9, 'requires_human_review' => false,
                'review_reason' => null, 'perfil_politico' => 'x',
            ]))]), 'INVALID_RESPONSE', 'invalid_output'],
        ];
    }

    // =========================================================================
    // 10. A 9B NUNCA ENVIA MENSAGENS
    // =========================================================================

    #[DataProvider('everyClassification')]
    public function test_no_classification_ever_produces_an_outgoing_message(string $classification): void
    {
        $this->enableAnalysis();

        Http::fake([
            '*' => Http::sequence()
                ->push($this->response([
                    'classification' => $classification,
                    'confidence' => 0.95,
                    'requires_human_review' => false,
                    'review_reason' => null,
                ]))
                ->push($this->response($this->extractionPayload())),
        ]);

        Queue::fake();
        [, $conversation, $state] = $this->scenario();
        $stageBefore = $state->current_stage;
        $endReasonBefore = $state->end_reason;

        // Texto neutro: garante que a classificação vem do provedor falso e não
        // de uma regra determinística.
        $this->interpret($this->incoming($conversation, 'Uma resposta aberta e neutra sobre o estado.'));

        $this->assertSame(
            0,
            ConversationMessage::where('conversation_id', $conversation->id)
                ->where('direction', ConversationMessageDirection::Outgoing)
                ->count(),
            "A classificação {$classification} não pode gerar mensagem de saída."
        );

        Queue::assertNotPushed(SendAutomatedConversationReplyJob::class);
        Queue::assertNotPushed(SendManualConversationReplyJob::class);

        // O estagio continua sendo decidido apenas pelas regras deterministicas.
        $state->refresh();
        $this->assertSame($stageBefore, $state->current_stage);
        $this->assertSame($endReasonBefore, $state->end_reason);
        $this->assertFalse($state->current_stage->isTerminal());
    }

    /** @return array<string, array{0: string}> */
    public static function everyClassification(): array
    {
        $cases = [];

        foreach (MessageClassification::cases() as $case) {
            $cases[$case->value] = [$case->value];
        }

        return $cases;
    }

    public function test_interpretation_never_reopens_a_terminal_flow(): void
    {
        $this->enableAnalysis();

        Http::fake([
            '*' => Http::sequence()
                ->push($this->response([
                    'classification' => 'question_answer',
                    'confidence' => 0.95,
                    'requires_human_review' => false,
                    'review_reason' => null,
                ]))
                ->push($this->response($this->extractionPayload())),
        ]);

        [, $conversation, $state] = $this->scenario();
        $state->forceFill([
            'current_stage' => ConversationFlowStage::Completed,
            'end_reason' => 'resposta_recebida',
        ])->save();

        $this->interpret($this->incoming($conversation, 'Mais uma resposta aberta depois do encerramento.'));

        $state->refresh();
        $this->assertSame(ConversationFlowStage::Completed, $state->current_stage);
        $this->assertSame('resposta_recebida', $state->end_reason);
        $this->assertSame(0, ConversationMessage::where('conversation_id', $conversation->id)
            ->where('direction', ConversationMessageDirection::Outgoing)->count());
    }

    public function test_only_the_human_review_flag_may_change_on_the_flow_state(): void
    {
        $this->enableAnalysis();

        Http::fake([
            '*' => Http::response($this->response([
                'classification' => 'sensitive_report',
                'confidence' => 0.99,
                'requires_human_review' => false,
                'review_reason' => null,
            ])),
        ]);

        [, $conversation, $state] = $this->scenario();
        $before = $state->only([
            'current_stage', 'selected_question_id', 'automated_messages_count',
            'attempts_count', 'end_reason', 'is_paused', 'completed_at',
        ]);

        $this->interpret($this->incoming($conversation, 'Uma resposta aberta e neutra sobre o estado.'));

        $state->refresh();
        $this->assertTrue($state->needs_human_review, 'A única alteração permitida e a marcação de revisão.');

        foreach ($before as $field => $value) {
            $this->assertEquals($value, $state->getAttribute($field), "O campo {$field} não pode ser alterado pela IA.");
        }
    }
}
