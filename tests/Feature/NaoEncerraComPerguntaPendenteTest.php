<?php

namespace Tests\Feature;

use App\Enums\ConversationFlowStage;
use App\Enums\ConversationMessageOrigin;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\SystemSetting;
use App\Services\ResponseGeneration\ConversationSuggestionService;
use App\Services\SystemSettingService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * O agradecimento não atropela uma pergunta que acabou de sair.
 *
 * Duas mensagens seguidas produzem dois jobs. O primeiro gerava o último
 * aprofundamento; o segundo chegava ao limite de turnos e encerrava. A pessoa
 * recebia a pergunta e o "obrigado, sua opinião foi registrada" no mesmo
 * minuto, e a resposta que ela ainda ia escrever caia num fluxo já encerrado.
 *
 * Aconteceu em produção com uma respondente que, depois de encerrada, ainda
 * escreveu a melhor resposta da conversa inteira.
 */
class NaoEncerraComPerguntaPendenteTest extends TestCase
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

    public function test_com_pergunta_no_ar_o_fluxo_nao_encerra(): void
    {
        [$state, $mensagem] = $this->conversaNoLimite();

        // A pergunta saiu depois da mensagem recebida, e ainda espera resposta.
        $this->perguntaEnviada($state, $mensagem);

        app(ConversationSuggestionService::class)->handleIncoming($mensagem);

        $this->assertSame(
            ConversationFlowStage::WaitingAnswer,
            $state->refresh()->current_stage,
            'Encerrar aqui fecharia a conversa em cima de uma pergunta recem-enviada.'
        );
    }

    public function test_sem_pergunta_pendente_o_fluxo_agradece_e_encerra(): void
    {
        [$state, $mensagem] = $this->conversaNoLimite();

        app(ConversationSuggestionService::class)->handleIncoming($mensagem);

        $this->assertTrue($state->refresh()->current_stage->isTerminal());
    }

    /** @return array{0: ConversationFlowState, 1: ConversationMessage} */
    private function conversaNoLimite(): array
    {
        $flow = ConversationFlow::factory()->create(['max_followups' => 2, 'transparency_enabled' => false]);
        $conversa = Conversation::factory()->create(['contact_id' => Contact::factory()->create()->id]);

        $state = ConversationFlowState::factory()->create([
            'conversation_id' => $conversa->id,
            'conversation_flow_id' => $flow->id,
            'current_stage' => ConversationFlowStage::WaitingAnswer,
            'followups_count' => 2,
            'expires_at' => now()->addDay(),
        ]);

        $mensagem = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'direction' => 'incoming',
            'body' => 'Falta segurança na saída da escola',
            'created_at' => now()->subMinute(),
        ]);

        return [$state, $mensagem];
    }

    private function perguntaEnviada(ConversationFlowState $state, ConversationMessage $depoisDe): void
    {
        ConversationMessage::factory()->create([
            'conversation_id' => $state->conversation_id,
            'direction' => 'outgoing',
            'origin' => ConversationMessageOrigin::ApprovedAi,
            'body' => 'O que mudaria para as famílias se essa presença fosse constante?',
            'created_at' => $depoisDe->created_at->addSeconds(5),
        ]);
    }
}
