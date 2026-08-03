<?php

namespace Tests\Feature;

use App\Enums\ConversationFlowStage;
use App\Enums\MessageClassification;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageClassification;
use App\Models\KnowledgeBase;
use App\Models\SystemSetting;
use App\Services\ResponseGeneration\ConversationSuggestionService;
use App\Services\SystemSettingService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pergunta factual sobre a candidata, em cascata.
 *
 * "Olha, nem sei quem e Norma" e classificada como `asks_about_norma` e virava
 * atendimento humano antes de o gerador rodar — a base de conhecimento, que
 * existe justamente para responder isso com fonte citada, nunca era consultada.
 *
 * A ordem agora e: base aprovada, depois texto institucional, e so então gente.
 * Sem base e sem texto, o encaminhamento continua sendo o destino — responder
 * sem fonte seria o modelo inventando quem e a candidata.
 */
class PerguntaFactualEmCascataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
        Queue::fake();

        $this->configurar('ai.response.mode', 'approval_required');
    }

    public function test_sem_base_e_sem_texto_a_pergunta_vai_para_atendimento(): void
    {
        $this->configurar('ai.response.factual_behavior', 'handoff');
        $state = $this->conversa(comBase: false);

        app(ConversationSuggestionService::class)->handleIncoming($this->pergunta($state));

        $this->assertSame(ConversationFlowStage::WaitingHuman, $state->refresh()->current_stage);
    }

    /**
     * Configurado para institucional, o texto fixo responde sem passar pelo
     * modelo: ele e escrito por gente, não cita fonte e não promete nada.
     */
    public function test_o_texto_institucional_responde_quando_nao_ha_base(): void
    {
        $this->configurar('ai.response.factual_behavior', 'institutional');
        $this->configurar('ai.response.institutional_text', 'A Professora Norma e educadora e concorre a deputada estadual. Nossa equipe pode contar mais, se quiser.');

        $state = $this->conversa(comBase: false);

        app(ConversationSuggestionService::class)->handleIncoming($this->pergunta($state));

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $state->conversation_id,
            'origin' => 'automation',
        ]);

        $this->assertNotSame(
            ConversationFlowStage::WaitingHuman,
            $state->refresh()->current_stage,
            'Respondida, a conversa não precisa de gente.'
        );
    }

    /**
     * Com base vinculada, a pergunta deixa de ser encaminhada de saída: o
     * gerador roda e tenta responder com fundamentação.
     */
    public function test_com_base_vinculada_o_gerador_chega_a_ser_chamado(): void
    {
        $this->configurar('ai.response.factual_behavior', 'knowledge');
        // A recuperação e opt-in global além de por fluxo.
        $this->configurar('knowledge.enabled', '1');
        $state = $this->conversa(comBase: true);

        $this->app->bind(\App\Contracts\ConversationResponseGenerator::class, fn () => new class implements \App\Contracts\ConversationResponseGenerator
        {
            public function generate(ConversationMessage $message, ConversationFlowState $state, array $options = []): ?\App\Models\ConversationReplySuggestion
            {
                // Chegou ao gerador: e isto que o teste verifica. Devolver nulo
                // isola a decisão do caminho, sem depender de provedor.
                throw new \RuntimeException('gerador-alcancado');
            }
        });

        // Sem exceção, o teste falha por não ter chegado ao gerador.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('gerador-alcancado');

        app(ConversationSuggestionService::class)->handleIncoming($this->pergunta($state));
    }

    /**
     * Configuração pedindo base, mas fluxo sem base nenhuma: encaminhar
     * continua certo. Responder ali seria inventar.
     */
    public function test_configuracao_pedindo_base_sem_base_no_fluxo_encaminha(): void
    {
        $this->configurar('ai.response.factual_behavior', 'knowledge');
        $state = $this->conversa(comBase: false);

        app(ConversationSuggestionService::class)->handleIncoming($this->pergunta($state));

        $this->assertSame(ConversationFlowStage::WaitingHuman, $state->refresh()->current_stage);
    }

    private function configurar(string $chave, string $valor): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => $chave],
            ['group' => str($chave)->before('.')->toString(), 'value' => $valor, 'type' => 'string', 'is_public' => false]
        );

        app(SystemSettingService::class)->forget();
    }

    private function conversa(bool $comBase): ConversationFlowState
    {
        $flow = ConversationFlow::factory()->create(['max_followups' => 15, 'transparency_enabled' => false]);

        if ($comBase) {
            $flow->knowledgeBases()->attach(KnowledgeBase::factory()->create(['status' => 'active'])->id);
        }

        $conversa = Conversation::factory()->create(['contact_id' => Contact::factory()->create()->id]);

        return ConversationFlowState::factory()->create([
            'conversation_id' => $conversa->id,
            'conversation_flow_id' => $flow->id,
            'current_stage' => ConversationFlowStage::AnswerReceived,
            'followups_count' => 1,
            'expires_at' => now()->addDay(),
        ]);
    }

    private function pergunta(ConversationFlowState $state): ConversationMessage
    {
        $mensagem = ConversationMessage::factory()->create([
            'conversation_id' => $state->conversation_id,
            'direction' => 'incoming',
            'body' => 'Olha, nem sei quem é Norma',
        ]);

        ConversationMessageClassification::factory()->create([
            'conversation_message_id' => $mensagem->id,
            'conversation_id' => $state->conversation_id,
            'classification' => MessageClassification::AsksAboutNorma,
            'confidence' => 0.95,
        ]);

        return $mensagem;
    }
}
