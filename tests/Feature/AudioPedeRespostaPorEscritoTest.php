<?php

namespace Tests\Feature;

use App\Enums\ConversationFlowStage;
use App\Jobs\TranscribeIncomingAudioJob;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\SystemSetting;
use App\Services\SystemSettingService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use Tests\TestCase;

/**
 * Áudio recebido não fica sem resposta.
 *
 * Cinco áudios chegaram a base e nenhum produziu reação: a mensagem entra com
 * corpo vazio e o motor so avalia texto. Para quem falou, o sistema
 * simplesmente não existiu.
 *
 * Enquanto a transcrição não estiver disponível, a saída honesta e avisar que o
 * áudio chegou e pedir o conteúdo por escrito.
 */
class AudioPedeRespostaPorEscritoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);

        $this->configurar('conversation_automation.window_start', '00:00');
        $this->configurar('conversation_automation.window_end', '23:59');
    }

    public function test_audio_recebe_pedido_de_resposta_por_escrito(): void
    {
        $state = $this->conversa();
        $audio = $this->audio($state);

        TranscribeIncomingAudioJob::dispatchSync($audio->id);

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $state->conversation_id,
            'origin' => 'automation',
        ]);

        $enviada = ConversationMessage::query()
            ->where('conversation_id', $state->conversation_id)
            ->where('direction', 'outgoing')
            ->latest('id')
            ->first();

        $this->assertStringContainsString('Recebi seu áudio', (string) $enviada->body);
    }

    /**
     * Quem manda três áudios seguidos não precisa do mesmo pedido três vezes:
     * repetir soaria como recusa.
     */
    public function test_o_pedido_sai_uma_vez_por_conversa(): void
    {
        $state = $this->conversa();

        TranscribeIncomingAudioJob::dispatchSync($this->audio($state)->id);
        TranscribeIncomingAudioJob::dispatchSync($this->audio($state)->id);

        $this->assertSame(
            1,
            ConversationMessage::query()->where('conversation_id', $state->conversation_id)->where('direction', 'outgoing')->count()
        );
    }

    public function test_pesquisa_encerrada_nao_recebe_o_pedido(): void
    {
        $state = $this->conversa(ConversationFlowStage::Completed);
        $audio = $this->audio($state);

        TranscribeIncomingAudioJob::dispatchSync($audio->id);

        $this->assertSame(
            0,
            ConversationMessage::query()->where('conversation_id', $state->conversation_id)->where('direction', 'outgoing')->count(),
            'A pesquisa terminou; a rede de segurança cuida do que vier depois.'
        );
    }

    /**
     * Mensagem de texto não passa por aqui: quem escreveu já e atendido pelo
     * motor normal.
     */
    public function test_texto_nao_dispara_o_pedido(): void
    {
        $state = $this->conversa();

        $texto = ConversationMessage::factory()->create([
            'conversation_id' => $state->conversation_id,
            'direction' => 'incoming',
            'message_type' => 'text',
            'body' => 'Falta praça no bairro.',
        ]);

        TranscribeIncomingAudioJob::dispatchSync($texto->id);

        $this->assertSame(
            0,
            ConversationMessage::query()->where('conversation_id', $state->conversation_id)->where('direction', 'outgoing')->count()
        );
    }

    private function configurar(string $chave, string $valor): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => $chave],
            ['group' => str($chave)->before('.')->toString(), 'value' => $valor, 'type' => 'string', 'is_public' => false]
        );

        app(SystemSettingService::class)->forget();
    }

    private function conversa(ConversationFlowStage $stage = ConversationFlowStage::WaitingAnswer): ConversationFlowState
    {
        $flow = ConversationFlow::factory()->create(['transparency_enabled' => false]);
        $conversa = Conversation::factory()->create(['contact_id' => Contact::factory()->create()->id]);

        return ConversationFlowState::factory()->create([
            'conversation_id' => $conversa->id,
            'conversation_flow_id' => $flow->id,
            'current_stage' => $stage,
            'expires_at' => now()->addDay(),
        ]);
    }

    private function audio(ConversationFlowState $state): ConversationMessage
    {
        return ConversationMessage::factory()->create([
            'conversation_id' => $state->conversation_id,
            'direction' => 'incoming',
            'message_type' => 'ptt',
            'has_media' => true,
            'body' => '',
        ]);
    }
}
