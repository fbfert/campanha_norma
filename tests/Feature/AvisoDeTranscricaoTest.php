<?php

namespace Tests\Feature;

use App\Enums\ConversationFlowStage;
use App\Enums\TranscriptionStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\MessageTranscription;
use App\Services\ConversationAutomation\ConversationAutomatedReplyService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Aviso de que o áudio virou texto.
 *
 * Mandar a voz de alguém para um provedor externo e outra categoria de dado, e
 * o aviso geral de automação não cobre isso — ele diz apenas que a mensagem e
 * automática.
 *
 * O aviso e separado de propósito: quem so escreveu não precisa ler "seus
 * áudios são transcritos", e aviso de privacidade que aparece sem motivo ensina
 * a ignorar aviso de privacidade.
 */
class AvisoDeTranscricaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
        Queue::fake();
    }

    public function test_quem_mandou_audio_recebe_o_aviso_junto_da_resposta(): void
    {
        $state = $this->conversa();
        $this->comTranscricao($state);

        $mensagem = app(ConversationAutomatedReplyService::class)
            ->queue($state, 'O que mais precisa melhorar por aí?', 'automated_question_queued');

        $this->assertStringContainsString('converti em texto', (string) $mensagem->body);
        $this->assertStringContainsString('O que mais precisa melhorar por aí?', (string) $mensagem->body);
    }

    public function test_quem_so_escreveu_nao_recebe_o_aviso(): void
    {
        $state = $this->conversa();

        $mensagem = app(ConversationAutomatedReplyService::class)
            ->queue($state, 'O que mais precisa melhorar por aí?', 'automated_question_queued');

        $this->assertStringNotContainsString('converti em texto', (string) $mensagem->body);
    }

    /**
     * Repetir a cada áudio viraria assinatura, e assinatura ninguém lê.
     */
    public function test_o_aviso_sai_uma_vez_por_conversa(): void
    {
        $state = $this->conversa();
        $this->comTranscricao($state);

        $servico = app(ConversationAutomatedReplyService::class);

        $primeira = $servico->queue($state, 'Primeira pergunta.', 'automated_question_queued');
        $segunda = $servico->queue($state, 'Segunda pergunta.', 'automated_question_queued');

        $this->assertStringContainsString('converti em texto', (string) $primeira->body);
        $this->assertStringNotContainsString('converti em texto', (string) $segunda->body);
    }

    /**
     * Transcrição sem fala reconhecível não gera aviso: nada foi convertido em
     * texto, e avisar seria informar algo que não aconteceu.
     */
    public function test_transcricao_vazia_nao_gera_aviso(): void
    {
        $state = $this->conversa();
        $this->comTranscricao($state, TranscriptionStatus::Empty);

        $mensagem = app(ConversationAutomatedReplyService::class)
            ->queue($state, 'O que mais precisa melhorar por aí?', 'automated_question_queued');

        $this->assertStringNotContainsString('converti em texto', (string) $mensagem->body);
    }

    private function conversa(): ConversationFlowState
    {
        $flow = ConversationFlow::factory()->create(['transparency_enabled' => false]);
        $conversa = Conversation::factory()->create(['contact_id' => Contact::factory()->create()->id]);

        return ConversationFlowState::factory()->create([
            'conversation_id' => $conversa->id,
            'conversation_flow_id' => $flow->id,
            'current_stage' => ConversationFlowStage::WaitingAnswer,
            'expires_at' => now()->addDay(),
        ]);
    }

    private function comTranscricao(ConversationFlowState $state, TranscriptionStatus $status = TranscriptionStatus::Succeeded): void
    {
        $audio = ConversationMessage::factory()->create([
            'conversation_id' => $state->conversation_id,
            'direction' => 'incoming',
            'message_type' => 'ptt',
            'has_media' => true,
            'body' => '',
        ]);

        MessageTranscription::create([
            'conversation_id' => $state->conversation_id,
            'conversation_message_id' => $audio->id,
            'status' => $status,
            'text' => $status === TranscriptionStatus::Succeeded ? 'Falta praça no bairro.' : null,
        ]);

        /*
         | A resposta é a este áudio.
         |
         | É o que o motor faz de verdade: `runDeterministicFlow` grava
         | `last_processed_message_id` antes de decidir o que responder. Sem
         | isto o cenário não descrevia "responder a um áudio", e sim "existir
         | um áudio em algum lugar da conversa" — que era exatamente o defeito.
         */
        $state->forceFill(['last_processed_message_id' => $audio->id])->save();
    }

    /**
     * Texto respondido não vira "recebi seu áudio" por causa de áudio antigo.
     *
     * Foi o que aconteceu na conversa 425, em 17/08/2026: o aviso saiu colado a
     * uma pergunta da pesquisa que respondia a um "sim" digitado, e o áudio era
     * de cinco dias antes. O sistema afirmou ter recebido um áudio que ninguém
     * mandou — e aviso de privacidade que erra o fato ensina a desconfiar do
     * resto.
     */
    public function test_audio_antigo_nao_faz_a_resposta_a_um_texto_avisar(): void
    {
        $state = $this->conversa();
        $this->comTranscricao($state);

        // Agora a pessoa escreve, e é a esta mensagem que se responde.
        $texto = ConversationMessage::factory()->create([
            'conversation_id' => $state->conversation_id,
            'direction' => 'incoming',
            'message_type' => 'text',
            'body' => 'sim',
        ]);
        $state->forceFill(['last_processed_message_id' => $texto->id])->save();

        $mensagem = app(ConversationAutomatedReplyService::class)
            ->queue($state->refresh(), 'O que mais precisa melhorar por aí?', 'automated_question_queued');

        $this->assertStringNotContainsString('converti em texto', (string) $mensagem->body);
        $this->assertStringContainsString('O que mais precisa melhorar por aí?', (string) $mensagem->body);
    }
}
