<?php

namespace Tests\Feature;

use App\Enums\ConversationFlowStage;
use App\Enums\ConversationFlowStatus;
use App\Events\ConversationMessageEvaluated;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowQuestion;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\SystemSetting;
use App\Services\ConversationAutomation\ConversationFlowService;
use App\Services\SystemSettingService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pesquisa com mais de uma pergunta na mesma conversa.
 *
 * `max_main_questions` existia no formulário, no relatório de governança e no
 * snapshot do lote — e em nenhum lugar do motor. O fluxo fazia uma pergunta e
 * encerrava, qualquer que fosse o número configurado. Quem preenchia "5" via o
 * campo aceito e a conversa terminar na primeira resposta.
 */
class PesquisaComVariasPerguntasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
        Queue::fake();
        Event::fake([ConversationMessageEvaluated::class]);

        foreach ([
            'conversation_automation.enabled' => '1',
            'conversation_automation.auto_send_enabled' => '1',
            'conversation_automation.window_start' => '00:00',
            'conversation_automation.window_end' => '23:59',
        ] as $key => $value) {
            SystemSetting::query()->updateOrCreate(['key' => $key], ['group' => 'conversation_automation', 'value' => $value, 'type' => 'string', 'is_public' => false]);
        }

        app(SystemSettingService::class)->forget();
    }

    public function test_uma_pergunta_configurada_encerra_na_primeira_resposta(): void
    {
        $state = $this->pesquisa(maxMainQuestions: 1, perguntas: 3);

        $this->responder($state);

        $this->assertSame(ConversationFlowStage::Completed, $state->refresh()->current_stage);
        $this->assertSame(1, $state->questionUsages()->whereNotNull('sent_at')->count());
    }

    public function test_pesquisa_de_tres_perguntas_continua_ate_a_terceira(): void
    {
        $state = $this->pesquisa(maxMainQuestions: 3, perguntas: 5);

        $this->responder($state);
        $this->assertSame(ConversationFlowStage::WaitingAnswer, $state->refresh()->current_stage, 'Depois da primeira resposta ainda ha pergunta a fazer.');
        $this->assertSame(2, $state->questionUsages()->whereNotNull('sent_at')->count());

        $this->responder($state);
        $this->assertSame(ConversationFlowStage::WaitingAnswer, $state->refresh()->current_stage);
        $this->assertSame(3, $state->questionUsages()->whereNotNull('sent_at')->count());

        $this->responder($state);
        $this->assertSame(ConversationFlowStage::Completed, $state->refresh()->current_stage, 'Atingido o teto, agradece e encerra.');
        $this->assertSame(3, $state->questionUsages()->whereNotNull('sent_at')->count());
    }

    public function test_a_mesma_pergunta_nao_se_repete_na_conversa(): void
    {
        $state = $this->pesquisa(maxMainQuestions: 3, perguntas: 5);

        $this->responder($state);
        $this->responder($state);

        $usadas = $state->questionUsages()->pluck('conversation_flow_question_id')->all();
        $this->assertSame($usadas, array_unique($usadas));
    }

    public function test_acaba_a_pergunta_antes_do_teto_e_a_pesquisa_encerra(): void
    {
        $state = $this->pesquisa(maxMainQuestions: 5, perguntas: 2);

        $this->responder($state);
        $this->assertSame(ConversationFlowStage::WaitingAnswer, $state->refresh()->current_stage);

        $this->responder($state);
        $this->assertSame(
            ConversationFlowStage::Completed,
            $state->refresh()->current_stage,
            'Sem pergunta nova a pesquisa termina normalmente, sem cair em atendimento humano.'
        );
    }

    public function test_opt_out_interrompe_a_pesquisa_no_meio(): void
    {
        $state = $this->pesquisa(maxMainQuestions: 3, perguntas: 5);

        $this->responder($state);
        $this->responder($state, 'não quero receber mais mensagens');

        $this->assertSame(ConversationFlowStage::OptedOut, $state->refresh()->current_stage);
    }

    private function pesquisa(int $maxMainQuestions, int $perguntas): ConversationFlowState
    {
        $flow = ConversationFlow::factory()->create([
            'status' => ConversationFlowStatus::Active,
            'max_main_questions' => $maxMainQuestions,
            'max_followups' => 0,
            'transparency_enabled' => false,
        ]);

        ConversationFlowQuestion::factory()->count($perguntas)->create([
            'conversation_flow_id' => $flow->id,
            'is_active' => true,
        ]);

        $conversation = Conversation::factory()->create(['contact_id' => Contact::factory()->create()->id]);
        $primeira = $flow->questions()->first();

        $state = ConversationFlowState::factory()->create([
            'conversation_id' => $conversation->id,
            'conversation_flow_id' => $flow->id,
            'selected_question_id' => $primeira->id,
            'current_stage' => ConversationFlowStage::WaitingAnswer,
            'automated_messages_count' => 1,
            'expires_at' => now()->addDay(),
        ]);

        // A primeira pergunta já foi enviada quando a conversa chega aqui.
        $state->questionUsages()->create([
            'conversation_flow_id' => $flow->id,
            'conversation_flow_question_id' => $primeira->id,
            'conversation_id' => $conversation->id,
            'question_snapshot' => $primeira->text,
            'sent_at' => now(),
            'result' => 'queued',
        ]);

        return $state;
    }

    private function responder(ConversationFlowState $state, string $texto = 'Acho que precisamos de mais investimento aqui'): void
    {
        $mensagem = ConversationMessage::factory()->create([
            'conversation_id' => $state->conversation_id,
            'direction' => 'incoming',
            'body' => $texto,
        ]);

        app(ConversationFlowService::class)->handleIncomingMessage($mensagem);
    }
}
