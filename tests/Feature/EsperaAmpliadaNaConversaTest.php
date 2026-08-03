<?php

namespace Tests\Feature;

use App\Enums\ConversationFlowStage;
use App\Events\ConversationMessageEvaluated;
use App\Jobs\GenerateConversationReplyJob;
use App\Listeners\DispatchConversationReplyGeneration;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\SystemSetting;
use App\Services\SystemSettingService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A espera antes de responder cresce quando a conversa engata.
 *
 * No começo, resposta rápida sustenta a conversa: quem acabou de autorizar e
 * fica dois minutos sem retorno acha que não funcionou. Depois de algumas
 * trocas o problema se inverte — a pessoa escreve em blocos, manda a ideia numa
 * mensagem, o exemplo em outra e o motivo numa terceira, e responder a primeira
 * frase joga fora as duas seguintes.
 */
class EsperaAmpliadaNaConversaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
        Queue::fake();

        $this->configurar('ai.response.mode', 'approval_required');
    }

    public function test_no_comeco_a_resposta_sai_rapido(): void
    {
        $this->disparar(aprofundamentos: 0);

        Queue::assertPushed(GenerateConversationReplyJob::class, fn ($job): bool => abs((float) $job->delay->diffInSeconds(now())) <= 25);
    }

    public function test_depois_da_terceira_troca_a_espera_amplia(): void
    {
        $this->disparar(aprofundamentos: 3);

        Queue::assertPushed(GenerateConversationReplyJob::class, fn ($job): bool => abs((float) $job->delay->diffInSeconds(now())) >= 85);
    }

    public function test_a_espera_ampliada_nunca_e_menor_que_a_padrao(): void
    {
        $this->configurar('ai.response.debounce_seconds', '120');
        $this->configurar('ai.response.extended_debounce_seconds', '30');

        $this->disparar(aprofundamentos: 5);

        Queue::assertPushed(GenerateConversationReplyJob::class, fn ($job): bool => abs((float) $job->delay->diffInSeconds(now())) >= 115);
    }

    public function test_o_ponto_de_virada_e_configuravel(): void
    {
        $this->configurar('ai.response.extended_debounce_after_turns', '1');

        $this->disparar(aprofundamentos: 1);

        Queue::assertPushed(GenerateConversationReplyJob::class, fn ($job): bool => abs((float) $job->delay->diffInSeconds(now())) >= 85);
    }

    private function configurar(string $chave, string $valor): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => $chave],
            ['group' => str($chave)->before('.')->toString(), 'value' => $valor, 'type' => 'string', 'is_public' => false]
        );

        app(SystemSettingService::class)->forget();
    }

    private function disparar(int $aprofundamentos): void
    {
        $conversa = Conversation::factory()->create(['contact_id' => Contact::factory()->create()->id]);

        $state = ConversationFlowState::factory()->create([
            'conversation_id' => $conversa->id,
            'conversation_flow_id' => ConversationFlow::factory()->create(['max_followups' => 15])->id,
            'current_stage' => ConversationFlowStage::AnswerReceived,
            'followups_count' => $aprofundamentos,
            'expires_at' => now()->addDay(),
        ]);

        $mensagem = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'direction' => 'incoming',
            'body' => 'E também falta iluminação na praça',
        ]);

        app(DispatchConversationReplyGeneration::class)->handle(
            new ConversationMessageEvaluated($mensagem, $state, true)
        );
    }
}
