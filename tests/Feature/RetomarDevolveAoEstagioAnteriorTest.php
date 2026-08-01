<?php

namespace Tests\Feature;

use App\Enums\ConversationFlowStage;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Models\User;
use App\Services\ConversationAutomation\ConversationFlowService;
use App\Services\ConversationAutomation\ConversationFlowStateMachine;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retomar devolve a conversa ao estágio em que ela parou.
 *
 * Antes, retomar mandava tudo para `waiting_permission`. Numa conversa que já
 * tinha autorização e esperava a resposta da pergunta, isso fazia a próxima
 * frase da pessoa — a opinião dela, o dado que a pesquisa existe para coletar —
 * ser lida como sim ou não. Quando não casava com nenhuma expressão, virava
 * ambígua e voltava para atendimento humano, num laço que ninguém entendia.
 */
class RetomarDevolveAoEstagioAnteriorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
    }

    public function test_conversa_que_esperava_resposta_volta_a_esperar_resposta(): void
    {
        $state = $this->estado(ConversationFlowStage::WaitingAnswer);

        app(ConversationFlowStateMachine::class)->markForHuman($state, 'handoff_to_human');
        $this->assertSame(ConversationFlowStage::WaitingHuman, $state->refresh()->current_stage);

        app(ConversationFlowService::class)->resume($state, $this->usuario());

        $this->assertSame(ConversationFlowStage::WaitingAnswer, $state->refresh()->current_stage);
        $this->assertNull($state->stage_before_hold, 'O registro de espera se consome ao ser usado.');
        $this->assertFalse((bool) $state->needs_human_review);
    }

    public function test_conversa_que_esperava_permissao_continua_esperando_permissao(): void
    {
        $state = $this->estado(ConversationFlowStage::WaitingPermission);

        app(ConversationFlowStateMachine::class)->markForHuman($state, 'ambiguous_reply');
        app(ConversationFlowService::class)->resume($state, $this->usuario());

        $this->assertSame(ConversationFlowStage::WaitingPermission, $state->refresh()->current_stage);
    }

    public function test_pausa_manual_tambem_lembra_de_onde_veio(): void
    {
        $state = $this->estado(ConversationFlowStage::WaitingAnswer);
        $usuario = $this->usuario();

        app(ConversationFlowService::class)->pause($state, $usuario);
        $this->assertSame(ConversationFlowStage::Paused, $state->refresh()->current_stage);

        app(ConversationFlowService::class)->resume($state, $usuario);

        $this->assertSame(ConversationFlowStage::WaitingAnswer, $state->refresh()->current_stage);
    }

    /**
     * Encaminhar duas vezes seguidas não pode sobrescrever o estágio guardado
     * com "atendimento humano", senão retomar devolveria a conversa para a
     * própria espera.
     */
    public function test_espera_dentro_de_espera_preserva_o_estagio_original(): void
    {
        $state = $this->estado(ConversationFlowStage::WaitingAnswer);
        $maquina = app(ConversationFlowStateMachine::class);

        $maquina->markForHuman($state, 'handoff_to_human');
        $maquina->transition($state, ConversationFlowStage::Paused, 'paused_by_user');

        app(ConversationFlowService::class)->resume($state, $this->usuario());

        $this->assertSame(ConversationFlowStage::WaitingAnswer, $state->refresh()->current_stage);
    }

    /**
     * Sem registro anterior — conversa antiga, de antes desta correção — o
     * pedido de permissão continua sendo o destino seguro.
     */
    public function test_sem_registro_anterior_volta_para_a_permissao(): void
    {
        $state = $this->estado(ConversationFlowStage::WaitingHuman);
        $state->forceFill(['stage_before_hold' => null])->save();

        app(ConversationFlowService::class)->resume($state, $this->usuario());

        $this->assertSame(ConversationFlowStage::WaitingPermission, $state->refresh()->current_stage);
    }

    private function estado(ConversationFlowStage $stage): ConversationFlowState
    {
        $flow = ConversationFlow::factory()->create();
        $conversation = Conversation::factory()->create(['contact_id' => Contact::factory()->create()->id]);

        return ConversationFlowState::factory()->create([
            'conversation_id' => $conversation->id,
            'conversation_flow_id' => $flow->id,
            'current_stage' => $stage,
            'expires_at' => now()->addDay(),
        ]);
    }

    private function usuario(): User
    {
        return User::factory()->create();
    }
}
