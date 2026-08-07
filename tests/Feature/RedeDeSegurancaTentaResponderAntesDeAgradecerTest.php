<?php

namespace Tests\Feature;

use App\Enums\ConversationFlowStage;
use App\Enums\ReplySuggestionAction;
use App\Enums\ReplySuggestionStatus;
use App\Enums\ResponseGenerationMode;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\ConversationReplySuggestion;
use App\Services\SystemSettingService;
use Database\Seeders\SendingSettingSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A rede de segurança tenta responder antes de agradecer.
 *
 * O agradecimento é o piso, não o primeiro recurso. Mandá-lo antes de tentar
 * responder transformaria toda conversa em protocolo: a pessoa escreve algo
 * concreto sobre a cidade dela e recebe de volta um aviso de que a mensagem
 * chegou.
 *
 * Como a rede contorna o autoenvio comum — que pode estar desligado de
 * propósito — ela exige mais confiança, não menos. Abaixo do limiar próprio, o
 * texto fica esperando aprovação e a pessoa recebe o aviso na hora, para não
 * ficar em silêncio enquanto isso.
 */
class RedeDeSegurancaTentaResponderAntesDeAgradecerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
        $this->seed(SendingSettingSeeder::class);

        app(SystemSettingService::class)->updateMany([
            'ai.enabled' => '1',
            'ai.response.mode' => ResponseGenerationMode::ApprovalRequired->value,
            'conversation_automation.enabled' => '1',
            'conversation_automation.auto_send_enabled' => '1',
            'conversation_automation.window_start' => '00:00',
            'conversation_automation.window_end' => '23:59',
        ]);
    }

    public function test_sugestao_confiante_e_enviada_no_lugar_do_agradecimento(): void
    {
        [$conversa, $recebida] = $this->conversaSemResposta();
        $this->sugestao($conversa, $recebida, grau: 0.96);

        $this->artisan('conversations:answer-pending')->assertSuccessful();

        $this->assertDatabaseHas('conversation_events', [
            'conversation_id' => $conversa->id,
            'event_type' => 'ai_reply_safety_net_sent',
        ]);

        $this->assertDatabaseMissing('conversation_events', [
            'conversation_id' => $conversa->id,
            'event_type' => 'pending_reply_acknowledged',
        ]);
    }

    /**
     * Abaixo do limiar a pessoa não fica em silêncio: recebe o aviso, e o texto
     * continua vivo esperando aprovação.
     */
    public function test_sugestao_pouco_confiante_gera_agradecimento_e_espera_aprovacao(): void
    {
        [$conversa, $recebida] = $this->conversaSemResposta();
        $sugestao = $this->sugestao($conversa, $recebida, grau: 0.40);

        $this->artisan('conversations:answer-pending')->assertSuccessful();

        $this->assertDatabaseHas('conversation_events', [
            'conversation_id' => $conversa->id,
            'event_type' => 'pending_reply_acknowledged',
        ]);

        $this->assertSame(ReplySuggestionStatus::Pending, $sugestao->fresh()->status);
    }

    /**
     * Texto que o próprio modelo marcou para revisão não sai sem revisão, por
     * mais confiante que ele diga estar.
     */
    public function test_sugestao_sinalizada_para_revisao_nao_e_enviada(): void
    {
        [$conversa, $recebida] = $this->conversaSemResposta();
        $this->sugestao($conversa, $recebida, grau: 0.99, sinalizada: true);

        $this->artisan('conversations:answer-pending')->assertSuccessful();

        $this->assertDatabaseMissing('conversation_events', [
            'conversation_id' => $conversa->id,
            'event_type' => 'ai_reply_safety_net_sent',
        ]);

        $this->assertDatabaseHas('conversation_events', [
            'conversation_id' => $conversa->id,
            'event_type' => 'pending_reply_acknowledged',
        ]);
    }

    /**
     * Quem escreve de novo dias depois é respondido de novo. A versão anterior
     * avisava uma única vez por conversa, para sempre: a segunda mensagem caía
     * no vazio.
     */
    public function test_mensagem_nova_depois_do_intervalo_recebe_novo_retorno(): void
    {
        Carbon::setTestNow('2026-08-03 10:00:00');

        [$conversa] = $this->conversaSemResposta();

        $this->artisan('conversations:answer-pending');
        $this->assertSame(1, $this->avisos($conversa));

        Carbon::setTestNow('2026-08-05 10:00:00');

        ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'direction' => 'incoming',
            'body' => 'Professor, conseguiu ver minha mensagem?',
            'created_at' => now()->subMinutes(30),
        ]);
        $conversa->forceFill(['last_incoming_message_at' => now()->subMinutes(30)])->save();

        $this->artisan('conversations:answer-pending');

        $this->assertSame(2, $this->avisos($conversa), 'Quem volta a escrever precisa ser respondido de novo.');
    }

    /**
     * Três mensagens numa tarde não viram três vezes a mesma frase.
     */
    public function test_intervalo_minimo_evita_repetir_o_mesmo_aviso(): void
    {
        Carbon::setTestNow('2026-08-03 10:00:00');

        [$conversa] = $this->conversaSemResposta();

        $this->artisan('conversations:answer-pending');

        Carbon::setTestNow('2026-08-03 12:00:00');

        ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'direction' => 'incoming',
            'body' => 'E outra coisa que eu queria falar...',
            'created_at' => now()->subMinutes(30),
        ]);
        $conversa->forceFill(['last_incoming_message_at' => now()->subMinutes(30)])->save();

        $this->artisan('conversations:answer-pending');

        $this->assertSame(1, $this->avisos($conversa));
    }


    /**
     * Conversa encaminhada para gente continua recebendo o aviso.
     *
     * O encaminhamento pausa a conversa, e a porta da automação recusa envio em
     * conversa pausada. O aviso era bloqueado com `conversa_pausada` — ou seja,
     * a garantia falhava exatamente no caso para o qual ela existe: alguém
     * escreveu, o sistema não soube responder, encaminhou para uma pessoa, e a
     * pessoa não recebeu nem o aviso.
     */
    public function test_conversa_pausada_ainda_recebe_o_aviso(): void
    {
        [$conversa] = $this->conversaSemResposta();

        ConversationFlowState::query()->where('conversation_id', $conversa->id)
            ->update(['is_paused' => true]);

        $this->artisan('conversations:answer-pending')->assertSuccessful();

        $mensagem = ConversationMessage::query()
            ->where('conversation_id', $conversa->id)
            ->where('direction', 'outgoing')
            ->latest('id')
            ->first();

        $this->assertNotNull($mensagem, 'A conversa pausada precisa receber o aviso.');
        $this->assertNotSame('AUTOMATION_BLOCKED', $mensagem->error_code);
    }

    /**
     * Saída que falhou não é resposta.
     *
     * Ela contava como tal, e a rede desistia da conversa depois da primeira
     * tentativa recusada: a linha ficava marcada como falha, e bastava existir
     * para a conversa nunca mais ser tentada.
     */
    public function test_saida_que_falhou_nao_conta_como_resposta(): void
    {
        [$conversa, $recebida] = $this->conversaSemResposta();

        ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'direction' => 'outgoing',
            'body' => 'Tentativa que não saiu.',
            'status' => \App\Enums\ConversationMessageStatus::Failed,
            'created_at' => now(),
        ]);

        $this->artisan('conversations:answer-pending')->assertSuccessful();

        $this->assertDatabaseHas('conversation_events', [
            'conversation_id' => $conversa->id,
            'event_type' => 'pending_reply_acknowledged',
        ]);
    }


    /**
     * Aviso que não saiu não segura a próxima tentativa.
     *
     * O evento era registrado no enfileiramento, e o envio acontece depois. Um
     * aviso recusado deixava a conversa em intervalo mínimo pelas horas
     * seguintes, como se a pessoa já tivesse sido respondida — por uma mensagem
     * que ninguém recebeu.
     */
    public function test_aviso_que_falhou_nao_segura_o_intervalo_minimo(): void
    {
        [$conversa] = $this->conversaSemResposta();

        $this->artisan('conversations:answer-pending');

        $aviso = ConversationMessage::query()
            ->where('conversation_id', $conversa->id)
            ->where('direction', 'outgoing')
            ->latest('id')
            ->firstOrFail();

        $aviso->forceFill(['status' => \App\Enums\ConversationMessageStatus::Failed])->save();

        $this->assertSame(1, $this->avisos($conversa));

        $this->artisan('conversations:answer-pending');

        $this->assertSame(2, $this->avisos($conversa), 'Aviso recusado precisa ser tentado de novo.');
    }

    private function avisos(Conversation $conversa): int
    {
        return \App\Models\ConversationEvent::query()
            ->where('conversation_id', $conversa->id)
            ->where('event_type', 'pending_reply_acknowledged')
            ->count();
    }

    /** @return array{0: Conversation, 1: ConversationMessage} */
    private function conversaSemResposta(): array
    {
        $flow = ConversationFlow::factory()->create(['transparency_enabled' => false, 'max_followups' => 3]);

        $conversa = Conversation::factory()->create([
            'contact_id' => Contact::factory()->create(['phone_normalized' => '5549999990001'])->id,
            'last_incoming_message_at' => now()->subMinutes(30),
        ]);

        ConversationFlowState::factory()->create([
            'conversation_id' => $conversa->id,
            'conversation_flow_id' => $flow->id,
            'current_stage' => ConversationFlowStage::WaitingHuman,
            'expires_at' => now()->addDay(),
        ]);

        $recebida = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'direction' => 'incoming',
            'body' => 'Falta transporte escolar no interior do município.',
            'created_at' => now()->subMinutes(30),
        ]);

        return [$conversa, $recebida];
    }

    private function sugestao(Conversation $conversa, ConversationMessage $origem, float $grau, bool $sinalizada = false): ConversationReplySuggestion
    {
        return ConversationReplySuggestion::factory()->create([
            'conversation_id' => $conversa->id,
            'conversation_flow_id' => ConversationFlowState::query()->where('conversation_id', $conversa->id)->value('conversation_flow_id'),
            'source_message_id' => $origem->id,
            'active_source_message_id' => $origem->id,
            'status' => ReplySuggestionStatus::Pending,
            'action' => ReplySuggestionAction::SuggestReply,
            'generated_text' => 'Transporte escolar no interior é um ponto que aparece muito. O que mais pesa aí: a distância ou o horário?',
            'confidence' => $grau,
            'requires_human_review' => $sinalizada,
            'handoff_reason' => null,
        ]);
    }
}
