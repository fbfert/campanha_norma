<?php

namespace Tests\Feature;

use App\Enums\ConversationFlowStage;
use App\Enums\ConversationMessageOrigin;
use App\Events\ConversationMessageEvaluated;
use App\Jobs\GenerateConversationReplyJob;
use App\Listeners\DispatchConversationReplyGeneration;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\SystemSetting;
use App\Services\Conversations\ConversationReplyService;
use App\Services\SystemSettingService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Placeholder resolvido em toda saída, e IA calada quando a 9A já respondeu.
 *
 * A automação renderizava por conta própria; resposta manual e sugestão
 * aprovada não renderizavam nada. Um `{cidade}` escrito a mão — ou copiado pelo
 * modelo do texto da pergunta — chegava literal no WhatsApp da pessoa, e foi
 * exatamente o que aconteceu numa resposta enviada em produção.
 */
class PlaceholderEmTodaSaidaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
        Queue::fake();
    }

    public function test_resposta_manual_sai_com_o_campo_resolvido(): void
    {
        $conversa = $this->conversa(['first_name' => 'Paulo', 'city' => 'Lages']);

        $mensagem = app(ConversationReplyService::class)->createPending(
            conversation: $conversa,
            body: 'Obrigado, {primeiro_nome}! Qual a principal área a melhorar em {cidade}?',
            origin: ConversationMessageOrigin::Manual,
        );

        $this->assertSame('Obrigado, Paulo! Qual a principal área a melhorar em Lages?', $mensagem->body);
    }

    public function test_sugestao_aprovada_tambem_e_resolvida(): void
    {
        $conversa = $this->conversa(['first_name' => 'Paulo', 'city' => 'Lages']);

        $mensagem = app(ConversationReplyService::class)->createPending(
            conversation: $conversa,
            body: 'Na sua opinião, o que precisa melhorar em {cidade}?',
            origin: ConversationMessageOrigin::ApprovedAi,
        );

        $this->assertStringNotContainsString('{cidade}', $mensagem->body);
        $this->assertStringContainsString('Lages', $mensagem->body);
    }

    public function test_campo_vazio_interrompe_o_envio_em_vez_de_mandar_a_chave_crua(): void
    {
        $conversa = $this->conversa(['first_name' => 'Paulo', 'city' => null]);

        $this->expectException(ValidationException::class);

        app(ConversationReplyService::class)->createPending(
            conversation: $conversa,
            body: 'O que precisa melhorar em {cidade}?',
            origin: ConversationMessageOrigin::Manual,
        );
    }

    public function test_texto_sem_placeholder_passa_intacto(): void
    {
        $conversa = $this->conversa(['first_name' => 'Paulo', 'city' => 'Lages']);

        $mensagem = app(ConversationReplyService::class)->createPending(
            conversation: $conversa,
            body: 'Obrigado pela sua contribuição!',
            origin: ConversationMessageOrigin::Manual,
        );

        $this->assertSame('Obrigado pela sua contribuição!', $mensagem->body);
    }

    // --- A IA não fala por cima da pergunta da pesquisa -----------------------

    public function test_nao_gera_sugestao_quando_a_9a_acabou_de_mandar_a_pergunta(): void
    {
        $this->ligarGeracao();

        $this->disparar(ConversationFlowStage::WaitingAnswer, flowEngineRan: true);

        Queue::assertNotPushed(GenerateConversationReplyJob::class);
    }

    public function test_gera_sugestao_quando_a_pessoa_respondeu_a_pergunta(): void
    {
        $this->ligarGeracao();

        $this->disparar(ConversationFlowStage::AnswerReceived, flowEngineRan: true);

        Queue::assertPushed(GenerateConversationReplyJob::class);
    }

    /**
     * Com a 9A desligada ou bloqueada, a 9C continua sendo o único caminho de
     * resposta. A independência entre as etapas não pode ser perdida por causa
     * desta verificação.
     */
    public function test_com_o_motor_deterministico_parado_a_ia_continua_gerando(): void
    {
        $this->ligarGeracao();

        $this->disparar(ConversationFlowStage::WaitingAnswer, flowEngineRan: false);

        Queue::assertPushed(GenerateConversationReplyJob::class);
    }

    private function ligarGeracao(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'ai.response.mode'],
            ['group' => 'ai', 'value' => 'approval_required', 'type' => 'string', 'is_public' => false]
        );

        app(SystemSettingService::class)->forget();
    }

    private function disparar(ConversationFlowStage $stage, bool $flowEngineRan): void
    {
        $conversa = $this->conversa(['city' => 'Lages']);

        $state = ConversationFlowState::factory()->create([
            'conversation_id' => $conversa->id,
            'conversation_flow_id' => ConversationFlow::factory()->create()->id,
            'current_stage' => $stage,
            'expires_at' => now()->addDay(),
        ]);

        $mensagem = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'direction' => 'incoming',
            'body' => 'sim',
        ]);

        app(DispatchConversationReplyGeneration::class)->handle(
            new ConversationMessageEvaluated($mensagem, $state, $flowEngineRan)
        );
    }

    private function conversa(array $contato): Conversation
    {
        return Conversation::factory()->create([
            'contact_id' => Contact::factory()->create($contato)->id,
        ]);
    }
}
