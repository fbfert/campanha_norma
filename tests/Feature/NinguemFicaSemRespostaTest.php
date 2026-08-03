<?php

namespace Tests\Feature;

use App\Enums\ConversationFlowStage;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Rede de segurança: ninguém fica sem resposta.
 *
 * A automação tem varias saídas legitimas que terminam em silêncio — pesquisa
 * encerrada, conversa encaminhada para gente, job perdido. Cada uma faz sentido
 * isolada, e o efeito somado e sempre o mesmo para quem escreveu: respondeu e
 * não recebeu nada. Aconteceu com quatro respondentes em dois dias.
 */
class NinguemFicaSemRespostaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
        Queue::fake();
    }

    public function test_pesquisa_encerrada_recebe_aviso_de_recebimento(): void
    {
        $state = $this->conversa(ConversationFlowStage::Completed, minutosAtras: 30);

        $this->artisan('conversations:answer-pending')->assertSuccessful();

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $state->conversation_id,
            'origin' => 'automation',
        ]);

        $this->assertDatabaseHas('conversation_events', [
            'conversation_id' => $state->conversation_id,
            'event_type' => 'pending_reply_acknowledged',
        ]);
    }

    public function test_o_aviso_sai_uma_vez_so(): void
    {
        $state = $this->conversa(ConversationFlowStage::Completed, minutosAtras: 30);

        $this->artisan('conversations:answer-pending');
        $enviadas = ConversationMessage::query()->where('conversation_id', $state->conversation_id)->where('direction', 'outgoing')->count();

        $this->artisan('conversations:answer-pending');

        $this->assertSame(
            $enviadas,
            ConversationMessage::query()->where('conversation_id', $state->conversation_id)->where('direction', 'outgoing')->count(),
            'Repetir o aviso trocaria o silêncio por outro incômodo.'
        );
    }

    /**
     * Fluxo vivo também chega ao piso.
     *
     * A versão anterior apenas reenfileirava a geração e seguia adiante,
     * confiando que a automação resolveria na rodada seguinte. Quando ela não
     * resolvia — e era justamente por não resolver que a conversa estava ali —
     * a rodada seguinte reenfileirava de novo, indefinidamente. O fluxo vivo
     * era o único caso que nunca alcançava o agradecimento, e por isso o único
     * em que alguém podia esperar para sempre.
     */
    public function test_fluxo_vivo_sem_resposta_utilizavel_tambem_recebe_retorno(): void
    {
        $state = $this->conversa(ConversationFlowStage::AnswerReceived, minutosAtras: 30);

        $this->artisan('conversations:answer-pending');

        $this->assertDatabaseHas('conversation_events', [
            'conversation_id' => $state->conversation_id,
            'event_type' => 'pending_reply_acknowledged',
        ]);
    }

    public function test_conversa_recente_nao_e_tocada(): void
    {
        $state = $this->conversa(ConversationFlowStage::Completed, minutosAtras: 2);

        $this->artisan('conversations:answer-pending');

        $this->assertDatabaseMissing('conversation_events', [
            'conversation_id' => $state->conversation_id,
            'event_type' => 'pending_reply_acknowledged',
        ]);
    }

    /**
     * A rede de segurança não pode furar as regras que o resto do sistema
     * respeita.
     */
    public function test_contato_nao_contatar_nao_recebe_aviso(): void
    {
        $state = $this->conversa(ConversationFlowStage::Completed, minutosAtras: 30);
        $state->conversation->contact->update(['do_not_contact' => true]);

        $this->artisan('conversations:answer-pending');

        $this->assertDatabaseMissing('conversation_events', [
            'conversation_id' => $state->conversation_id,
            'event_type' => 'pending_reply_acknowledged',
        ]);
    }

    public function test_conversa_com_resposta_enviada_depois_nao_entra(): void
    {
        $state = $this->conversa(ConversationFlowStage::Completed, minutosAtras: 30);

        ConversationMessage::factory()->create([
            'conversation_id' => $state->conversation_id,
            'direction' => 'outgoing',
            'body' => 'Já respondemos aqui.',
        ]);

        $this->artisan('conversations:answer-pending');

        $this->assertDatabaseMissing('conversation_events', [
            'conversation_id' => $state->conversation_id,
            'event_type' => 'pending_reply_acknowledged',
        ]);
    }

    /**
     * Conversa que nunca entrou em pesquisa e a que mais ficou no vácuo: foi
     * abordada a mão, respondeu, e não ha automação nenhuma cuidando dela.
     */
    public function test_conversa_sem_fluxo_tambem_recebe_o_aviso(): void
    {
        $conversa = Conversation::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'last_incoming_message_at' => now()->subMinutes(30),
        ]);

        ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'direction' => 'incoming',
            'body' => 'Oi professor, tudo bem?',
            'created_at' => now()->subMinutes(30),
        ]);

        $this->artisan('conversations:answer-pending')->assertSuccessful();

        $this->assertDatabaseHas('conversation_events', [
            'conversation_id' => $conversa->id,
            'event_type' => 'pending_reply_acknowledged',
        ]);
    }

    private function conversa(ConversationFlowStage $stage, int $minutosAtras): ConversationFlowState
    {
        $flow = ConversationFlow::factory()->create(['transparency_enabled' => false]);
        $conversa = Conversation::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'last_incoming_message_at' => now()->subMinutes($minutosAtras),
        ]);

        ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'direction' => 'incoming',
            'body' => 'Penso num programa conectando escolas, universidades e empresas.',
            'created_at' => now()->subMinutes($minutosAtras),
        ]);

        return ConversationFlowState::factory()->create([
            'conversation_id' => $conversa->id,
            'conversation_flow_id' => $flow->id,
            'current_stage' => $stage,
            'expires_at' => now()->addDay(),
        ]);
    }
}
