<?php

namespace Tests\Feature;

use App\Enums\ConversationFlowStage;
use App\Enums\ConversationFlowStatus;
use App\Enums\ResponseGenerationMode;
use App\Events\ConversationMessageEvaluated;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowQuestion;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\SystemSetting;
use App\Services\ConversationAutomation\ConversationFlowService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Quem encerra o fluxo depois da resposta.
 *
 * A 9A foi escrita quando não existia aprofundamento: ela agradecia e encerrava
 * na primeira resposta. Como `completed` e terminal, toda pergunta gerada pela
 * 9C a partir daquela resposta era recusada depois com `fluxo_encerrado` — o
 * aprofundamento configurado no fluxo nunca acontecia, e ninguém percebia
 * porque a conversa terminava com um agradecimento educado.
 */
class AprofundamentoAssumeAposRespostaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
        $this->ligarAutomacao();

        // O que esta sob teste e a decisão da 9A. O ouvinte da 9C reage ao
        // mesmo evento e, sem provedor de IA no teste, encaminharia tudo para
        // atendimento humano — apagando justamente o estágio a verificar.
        Event::fake([ConversationMessageEvaluated::class]);
    }

    public function test_fluxo_sem_aprofundamento_agradece_e_encerra(): void
    {
        $state = $this->conversaAguardandoResposta(maxFollowups: 0);

        app(ConversationFlowService::class)->handleIncomingMessage($this->resposta($state));

        $this->assertSame(ConversationFlowStage::Completed, $state->refresh()->current_stage);
        $this->assertDatabaseHas('conversation_messages', ['conversation_id' => $state->conversation_id, 'origin' => 'automation']);
    }

    public function test_fluxo_com_aprofundamento_para_em_resposta_recebida(): void
    {
        $state = $this->conversaAguardandoResposta(maxFollowups: 2);

        app(ConversationFlowService::class)->handleIncomingMessage($this->resposta($state));

        $state->refresh();
        $this->assertSame(ConversationFlowStage::AnswerReceived, $state->current_stage);
        $this->assertFalse($state->current_stage->isTerminal(), 'O estágio precisa continuar aberto para a 9C gerar a próxima pergunta.');
        $this->assertDatabaseHas('conversation_events', ['conversation_id' => $state->conversation_id, 'event_type' => 'automation_deepening_handover']);
    }

    public function test_sem_geracao_ligada_o_fluxo_encerra_mesmo_com_aprofundamento(): void
    {
        SystemSetting::query()->updateOrCreate(['key' => 'ai.response.mode'], ['group' => 'ai', 'value' => 'disabled', 'type' => 'string', 'is_public' => false]);
        app(\App\Services\SystemSettingService::class)->forget();

        $state = $this->conversaAguardandoResposta(maxFollowups: 2);

        app(ConversationFlowService::class)->handleIncomingMessage($this->resposta($state));

        $this->assertSame(
            ConversationFlowStage::Completed,
            $state->refresh()->current_stage,
            'Sem geração ligada não ha quem assuma: encerrar e melhor que deixar a pessoa sem resposta.'
        );
    }

    public function test_pedido_de_opt_out_continua_prevalecendo(): void
    {
        $state = $this->conversaAguardandoResposta(maxFollowups: 2);

        app(ConversationFlowService::class)->handleIncomingMessage($this->resposta($state, 'quero parar de receber mensagens'));

        $this->assertSame(ConversationFlowStage::OptedOut, $state->refresh()->current_stage);
    }

    private function ligarAutomacao(): void
    {
        foreach ([
            'conversation_automation.enabled' => '1',
            'conversation_automation.auto_send_enabled' => '1',
            // Janela aberta: o agradecimento da 9A passa pelo mesmo guard de
            // horário, e o teste não pode depender da hora em que roda.
            'conversation_automation.window_start' => '00:00',
            'conversation_automation.window_end' => '23:59',
            'ai.response.mode' => 'approval_required',
        ] as $key => $value) {
            SystemSetting::query()->updateOrCreate(['key' => $key], [
                'group' => str($key)->before('.')->toString(),
                'value' => $value,
                'type' => 'string',
                'is_public' => false,
            ]);
        }

        app(\App\Services\SystemSettingService::class)->forget();
    }

    private function conversaAguardandoResposta(int $maxFollowups): ConversationFlowState
    {
        $flow = ConversationFlow::factory()->create([
            'status' => ConversationFlowStatus::Active,
            'max_followups' => $maxFollowups,
            'response_mode' => $maxFollowups > 0 ? ResponseGenerationMode::AutoSendLimited->value : null,
        ]);

        $question = ConversationFlowQuestion::factory()->create(['conversation_flow_id' => $flow->id, 'is_active' => true]);
        $contact = Contact::factory()->create();
        $conversation = Conversation::factory()->create(['contact_id' => $contact->id]);

        return ConversationFlowState::factory()->create([
            'conversation_id' => $conversation->id,
            'conversation_flow_id' => $flow->id,
            'selected_question_id' => $question->id,
            'current_stage' => ConversationFlowStage::WaitingAnswer,
            'automated_messages_count' => 1,
            'expires_at' => now()->addDay(),
        ]);
    }

    private function resposta(ConversationFlowState $state, string $texto = 'Acho que precisamos melhorar as ruas da cidade'): ConversationMessage
    {
        return ConversationMessage::factory()->create([
            'conversation_id' => $state->conversation_id,
            'direction' => 'incoming',
            'body' => $texto,
        ]);
    }
}
