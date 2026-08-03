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
use App\Models\SystemSetting;
use App\Services\ResponseGeneration\ConversationSuggestionService;
use App\Services\SystemSettingService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pedido de atendimento humano não se perde no agrupamento.
 *
 * Aconteceu em produção: uma respondente escreveu "podemos marcar para
 * conversar pessoalmente?" e, um minuto depois, um elogio a candidata. O
 * agrupamento fez o job da mensagem mais nova responder por todas, e so ela era
 * verificada — o pedido de conversa, classificado corretamente como
 * `human_requested`, foi engolido, e a pessoa recebeu uma pergunta de pesquisa
 * sobre o elogio, como se ninguém tivesse lido o pedido.
 */
class PedidoDeGenteNaoSePerdeNoAgrupamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
        Queue::fake();

        SystemSetting::query()->updateOrCreate(
            ['key' => 'ai.response.mode'],
            ['group' => 'ai', 'value' => 'approval_required', 'type' => 'string', 'is_public' => false]
        );

        app(SystemSettingService::class)->forget();
    }

    public function test_pedido_no_meio_do_bloco_encaminha_para_atendimento(): void
    {
        $state = $this->conversa();

        $this->recebida($state, 'Podemos marcar para conversar pessoalmente?', MessageClassification::HumanRequested);
        $ultima = $this->recebida($state, 'Conheço a Profa Norma, pessoa exemplar.', MessageClassification::QuestionAnswer);

        app(ConversationSuggestionService::class)->handleIncoming($ultima);

        $this->assertSame(
            ConversationFlowStage::WaitingHuman,
            $state->refresh()->current_stage,
            'O pedido veio antes do elogio, e continua sendo um pedido.'
        );
    }

    public function test_bloco_sem_pedido_segue_o_caminho_normal(): void
    {
        $this->semProvedorDeIa();
        $state = $this->conversa();

        $this->recebida($state, 'Falta praça no bairro.', MessageClassification::QuestionAnswer);
        $ultima = $this->recebida($state, 'E também iluminação.', MessageClassification::QuestionAnswer);

        app(ConversationSuggestionService::class)->handleIncoming($ultima);

        $this->assertNotSame(ConversationFlowStage::WaitingHuman, $state->refresh()->current_stage);
    }

    /**
     * O bloco termina na última resposta enviada. Pedido de uma rodada anterior,
     * já respondido por gente, não pode encaminhar a conversa de novo.
     */
    public function test_pedido_de_rodada_ja_respondida_nao_conta(): void
    {
        $this->semProvedorDeIa();
        $state = $this->conversa();

        $this->recebida($state, 'Podemos conversar pessoalmente?', MessageClassification::HumanRequested);

        ConversationMessage::factory()->create([
            'conversation_id' => $state->conversation_id,
            'direction' => 'outgoing',
            'body' => 'Claro, nossa equipe entra em contato.',
        ]);

        $ultima = $this->recebida($state, 'Enquanto isso: falta praça no bairro.', MessageClassification::QuestionAnswer);

        app(ConversationSuggestionService::class)->handleIncoming($ultima);

        $this->assertNotSame(ConversationFlowStage::WaitingHuman, $state->refresh()->current_stage);
    }

    /**
     * Gerador que não produz nada, para isolar a decisão de encaminhamento.
     *
     * Sem isto, a falha do provedor de IA no teste encaminharia a conversa por
     * outro motivo, e o teste passaria dizendo o contrário do que verifica.
     */
    private function semProvedorDeIa(): void
    {
        $this->app->bind(\App\Contracts\ConversationResponseGenerator::class, fn () => new class implements \App\Contracts\ConversationResponseGenerator
        {
            public function generate(ConversationMessage $message, ConversationFlowState $state, array $options = []): ?\App\Models\ConversationReplySuggestion
            {
                return null;
            }
        });
    }

    private function conversa(): ConversationFlowState
    {
        $flow = ConversationFlow::factory()->create(['max_followups' => 15]);
        $conversa = Conversation::factory()->create(['contact_id' => Contact::factory()->create()->id]);

        return ConversationFlowState::factory()->create([
            'conversation_id' => $conversa->id,
            'conversation_flow_id' => $flow->id,
            'current_stage' => ConversationFlowStage::AnswerReceived,
            'followups_count' => 1,
            'expires_at' => now()->addDay(),
        ]);
    }

    private function recebida(ConversationFlowState $state, string $texto, MessageClassification $classificacao): ConversationMessage
    {
        $mensagem = ConversationMessage::factory()->create([
            'conversation_id' => $state->conversation_id,
            'direction' => 'incoming',
            'body' => $texto,
        ]);

        ConversationMessageClassification::factory()->create([
            'conversation_message_id' => $mensagem->id,
            'conversation_id' => $state->conversation_id,
            'classification' => $classificacao,
            'confidence' => 0.95,
        ]);

        return $mensagem;
    }
}
