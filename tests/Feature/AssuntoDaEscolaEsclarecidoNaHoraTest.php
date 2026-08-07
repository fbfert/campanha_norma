<?php

namespace Tests\Feature;

use App\Enums\ConversationFlowStage;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowQuestion;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Services\ConversationAutomation\ConversationFlowService;
use App\Services\SystemSettingService;
use Database\Seeders\SendingSettingSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Quem confunde a campanha com assunto da escola é esclarecido na hora.
 *
 * O polo Rainbow fala com muita gente por outro motivo — mensalidade, matrícula,
 * boleto — e do lado delas é a mesma pessoa escrevendo. Uma contatada respondeu
 * ao convite com "acho que deve ser sobre as mensalidades atrasadas né?
 * segunda-feira eu pago", constrangida, prometendo pagar.
 *
 * Encaminhar para atendimento humano estava certo e era lento demais: cada
 * minuto de silêncio confirmava a leitura dela. Desfazer o mal-entendido não
 * exige julgamento humano nenhum.
 */
class AssuntoDaEscolaEsclarecidoNaHoraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
        $this->seed(SendingSettingSeeder::class);

        Http::fake([
            '127.0.0.1:3100/api/status' => Http::response(['success' => true, 'data' => ['status' => 'connected']], 200),
            '127.0.0.1:3100/api/*' => fn () => Http::response(['success' => true, 'data' => [
                'request_id' => (string) \Illuminate\Support\Str::uuid(),
                'status' => 'sent',
                'external_message_id' => 'wamid.'.\Illuminate\Support\Str::random(16),
                'sent_at' => now()->toIso8601String(),
            ]], 200),
        ]);

        app(SystemSettingService::class)->updateMany([
            'conversation_automation.enabled' => '1',
            'conversation_automation.auto_send_enabled' => '1',
            'conversation_automation.window_start' => '00:00',
            'conversation_automation.window_end' => '23:59',
        ]);
    }

    public function test_a_pessoa_recebe_o_esclarecimento_e_a_pergunta(): void
    {
        [$state, $mensagem] = $this->cenario('Olá, acho que deve ser sobre as mensalidades atrasadas né? segunda pago');

        app(ConversationFlowService::class)->handleIncomingMessage($mensagem);

        $enviadas = ConversationMessage::query()
            ->where('conversation_id', $state->conversation_id)
            ->where('direction', 'outgoing')
            ->pluck('body')
            ->implode(' ');

        $this->assertStringContainsString('Não é sobre a escola', $enviadas);
        $this->assertStringContainsString('prof Norma', $enviadas);
    }

    public function test_o_esclarecimento_fica_registrado(): void
    {
        [$state, $mensagem] = $this->cenario('bom dia, é sobre o boleto?');

        app(ConversationFlowService::class)->handleIncomingMessage($mensagem);

        $this->assertDatabaseHas('conversation_events', [
            'conversation_id' => $state->conversation_id,
            'event_type' => 'automation_school_matter_clarified',
        ]);
    }

    /**
     * Conversa comum não é tocada: a detecção é por termo, e termo do polo não
     * aparece em resposta sobre a cidade.
     */
    public function test_resposta_comum_segue_o_fluxo_normal(): void
    {
        [$state, $mensagem] = $this->cenario('Acho que precisa investir no transporte escolar do interior');

        app(ConversationFlowService::class)->handleIncomingMessage($mensagem);

        $this->assertDatabaseMissing('conversation_events', [
            'conversation_id' => $state->conversation_id,
            'event_type' => 'automation_school_matter_clarified',
        ]);
    }

    /** @return array{0: ConversationFlowState, 1: ConversationMessage} */
    private function cenario(string $texto): array
    {
        $flow = ConversationFlow::factory()->create(['transparency_enabled' => false]);
        ConversationFlowQuestion::factory()->create(['conversation_flow_id' => $flow->id, 'is_active' => true]);

        $conversa = Conversation::factory()->create([
            'contact_id' => Contact::factory()->create(['phone_normalized' => '5549999990001'])->id,
        ]);

        $state = ConversationFlowState::factory()->create([
            'conversation_id' => $conversa->id,
            'conversation_flow_id' => $flow->id,
            'current_stage' => ConversationFlowStage::WaitingPermission,
            'expires_at' => now()->addDay(),
        ]);

        $mensagem = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'direction' => 'incoming',
            'body' => $texto,
        ]);

        return [$state, $mensagem];
    }
}
