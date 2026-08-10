<?php

namespace Tests\Feature;

use App\Enums\ConversationFlowStage;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Services\ConversationAutomation\UnreadableMediaResponder;
use App\Services\SystemSettingService;
use Database\Seeders\SendingSettingSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Figurinha, imagem, vídeo e documento não ficam no vácuo.
 *
 * O motor de fluxo só avalia `text` e a transcrição só trata áudio: esses tipos
 * não caíam em lugar nenhum e produziam silêncio absoluto. Uma figurinha ficou
 * dois dias sem retorno, e a conversa só voltou porque a pessoa escreveu de
 * novo por conta própria.
 *
 * Isto não lê a mídia. Diz que chegou e que o caminho é escrever — que é o
 * mínimo que se deve a quem mandou.
 */
class FigurinhaEImagemNaoFicamNoVacuoTest extends TestCase
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
                'request_id' => (string) Str::uuid(),
                'status' => 'sent',
                'external_message_id' => 'wamid.'.Str::random(16),
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

    #[\PHPUnit\Framework\Attributes\DataProvider('midias')]
    public function test_midia_sem_texto_recebe_pedido_para_escrever(string $tipo): void
    {
        [$conversa, $mensagem] = $this->cenario($tipo);

        $enviou = app(UnreadableMediaResponder::class)
            ->askForText($mensagem, 'conversation_automation.media_reply_text');

        $this->assertTrue($enviou, "Mídia do tipo {$tipo} precisa receber retorno.");

        $this->assertDatabaseHas('conversation_events', [
            'conversation_id' => $conversa->id,
            'event_type' => UnreadableMediaResponder::ASKED_FOR_TEXT_EVENT,
        ]);
    }

    /**
     * A regra vale nos dois caminhos de entrada.
     *
     * Ela nasceu só no webhook, e por isso um áudio que entrou pela
     * sincronização passou direto: o João Pedro mandou um áudio no dia 07, a
     * sessão do WhatsApp estava fora do ar, a sincronização o trouxe no dia 10
     * e ele recebeu "já te respondo" — sem nunca saber que não conseguimos
     * ouvi-lo. Mesmo formato do eco de saída duplicado, que também precisou
     * existir nos dois caminhos.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('midias')]
    public function test_a_regra_reconhece_a_midia_venha_de_onde_vier(string $tipo): void
    {
        [, $mensagem] = $this->cenario($tipo);

        $this->assertTrue(app(UnreadableMediaResponder::class)->handles($mensagem));
    }

    /** Áudio tem caminho próprio, com transcrição, e não passa por aqui. */
    public function test_audio_fica_de_fora_porque_tem_caminho_proprio(): void
    {
        [, $mensagem] = $this->cenario('ptt');

        $this->assertFalse(app(UnreadableMediaResponder::class)->handles($mensagem));
    }

    /** Saída nossa nunca dispara pedido de texto. */
    public function test_saida_nao_dispara_pedido(): void
    {
        [, $mensagem] = $this->cenario('sticker');
        $mensagem->forceFill(['direction' => 'outgoing'])->save();

        $this->assertFalse(app(UnreadableMediaResponder::class)->handles($mensagem->fresh()));
    }

    /**
     * Fluxo expirado ainda recebe o aviso.
     *
     * O aviso é piso, não passo de pesquisa. Amarrá-lo às condições do fluxo o
     * faz morrer justamente quando mais importa: o áudio do João Pedro chegou
     * no dia 07, a sessão do WhatsApp caiu três minutos depois e ficou 64 horas
     * fora, e o fluxo dele expirou sozinho no dia 09. Quando enfim houve
     * conexão, o aviso foi recusado com `fluxo_expirado` — a pessoa perdeu a
     * resposta por causa de uma queda nossa.
     */
    public function test_fluxo_expirado_ainda_recebe_o_aviso(): void
    {
        [$conversa, $mensagem] = $this->cenario('sticker');

        ConversationFlowState::query()
            ->where('conversation_id', $conversa->id)
            ->update(['expires_at' => now()->subDay()]);

        $this->assertTrue(app(UnreadableMediaResponder::class)
            ->askForText($mensagem, 'conversation_automation.media_reply_text'));
    }

    /** @return array<int, array<int, string>> */
    public static function midias(): array
    {
        return [['sticker'], ['image'], ['video'], ['document']];
    }

    /**
     * Quem prefere mandar mídia vai mandar de novo, e repetir a mesma frase a
     * cada tentativa troca o silêncio por outro incômodo.
     */
    public function test_o_pedido_sai_uma_vez_por_conversa(): void
    {
        [$conversa, $mensagem] = $this->cenario('sticker');

        $responder = app(UnreadableMediaResponder::class);

        $this->assertTrue($responder->askForText($mensagem, 'conversation_automation.media_reply_text'));
        $this->assertFalse($responder->askForText($mensagem, 'conversation_automation.media_reply_text'));

        $this->assertSame(1, ConversationMessage::query()
            ->where('conversation_id', $conversa->id)
            ->where('direction', 'outgoing')
            ->count());
    }

    /**
     * Conversa encerrada ou pausada não recebe: a decisão de parar vale mais
     * que o pedido.
     */
    public function test_conversa_pausada_nao_recebe(): void
    {
        [, $mensagem] = $this->cenario('image');

        ConversationFlowState::query()
            ->where('conversation_id', $mensagem->conversation_id)
            ->update(['is_paused' => true]);

        $this->assertFalse(app(UnreadableMediaResponder::class)
            ->askForText($mensagem, 'conversation_automation.media_reply_text'));
    }

    /** @return array{0: Conversation, 1: ConversationMessage} */
    private function cenario(string $tipo): array
    {
        $flow = ConversationFlow::factory()->create(['transparency_enabled' => false]);

        $conversa = Conversation::factory()->create([
            'contact_id' => Contact::factory()->create(['phone_normalized' => '5549999990001'])->id,
        ]);

        ConversationFlowState::factory()->create([
            'conversation_id' => $conversa->id,
            'conversation_flow_id' => $flow->id,
            'current_stage' => ConversationFlowStage::WaitingAnswer,
            'expires_at' => now()->addDay(),
        ]);

        $mensagem = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'direction' => 'incoming',
            'message_type' => $tipo,
            'has_media' => true,
            'body' => null,
        ]);

        return [$conversa, $mensagem];
    }
}
