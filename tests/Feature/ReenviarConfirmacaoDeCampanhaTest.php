<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignParticipation;
use Database\Seeders\SendingSettingSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * O reenvio da confirmação.
 *
 * Quem se inscreveu durante uma queda fica num estado esquisito: está na lista,
 * concorre ao sorteio, e não sabe de nada. `campanhas:reprocessar` recupera a
 * inscrição de propósito sem responder — varre histórico, e histórico varrido
 * em massa não deve virar mensagem em massa.
 *
 * O que estes testes cobram é o cuidado, mais do que o envio: sem `--enviar`
 * nada sai, e quem já foi respondido não é respondido de novo. Mensagem
 * duplicada para um eleitor não tem como ser recolhida.
 */
class ReenviarConfirmacaoDeCampanhaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
        $this->seed(SendingSettingSeeder::class);

        Queue::fake();
        Http::fake();
    }

    public function test_sem_a_opcao_enviar_nada_sai(): void
    {
        $this->inscricaoSemResposta();

        $this->artisan('campanhas:reenviar-confirmacao') // ortografia:ignorar - nome do comando, digitado no terminal e por isso sem acento
            ->expectsOutputToContain('Nada foi enviado')
            ->assertSuccessful();

        $this->assertSame(0, ConversationMessage::where('origin', 'automation')->count());
    }

    public function test_recusar_a_pergunta_nao_envia(): void
    {
        $this->inscricaoSemResposta();

        $this->artisan('campanhas:reenviar-confirmacao --enviar') // ortografia:ignorar - nome do comando, digitado no terminal e por isso sem acento
            ->expectsConfirmation('Enviar a confirmação para 1 pessoa? Mensagem enviada não volta atrás.', 'no')
            ->expectsOutputToContain('Cancelado')
            ->assertSuccessful();

        $this->assertSame(0, ConversationMessage::where('origin', 'automation')->count());
    }

    public function test_confirmando_a_mensagem_e_enfileirada(): void
    {
        $inscricao = $this->inscricaoSemResposta();

        $this->artisan('campanhas:reenviar-confirmacao --enviar') // ortografia:ignorar - nome do comando, digitado no terminal e por isso sem acento
            ->expectsConfirmation('Enviar a confirmação para 1 pessoa? Mensagem enviada não volta atrás.', 'yes')
            ->assertSuccessful();

        $enviada = ConversationMessage::query()
            ->where('conversation_id', $inscricao->message->conversation_id)
            ->where('origin', 'automation')
            ->first();

        $this->assertNotNull($enviada);
        $this->assertStringContainsString('Inscrição confirmada', (string) $enviada->body);
    }

    /**
     * Quem já foi respondido não entra na lista.
     *
     * O evento de resposta enfileirada é o registro consultado. Ignorá-lo faria
     * o comando reenviar a confirmação para a campanha inteira toda vez que
     * alguém o rodasse — e mensagem repetida para eleitor não se recolhe.
     */
    public function test_quem_ja_recebeu_nao_recebe_de_novo(): void
    {
        $inscricao = $this->inscricaoSemResposta();

        ConversationEvent::create([
            'conversation_id' => $inscricao->message->conversation_id,
            'conversation_message_id' => $inscricao->conversation_message_id,
            'event_type' => 'keyword_campaign_reply_queued',
            'description' => 'Resposta de campanha enfileirada.',
            'metadata' => ['participation_id' => $inscricao->id],
        ]);

        $this->artisan('campanhas:reenviar-confirmacao --enviar') // ortografia:ignorar - nome do comando, digitado no terminal e por isso sem acento
            ->expectsOutputToContain('Nenhuma inscrição sem confirmação.')
            ->assertSuccessful();

        $this->assertSame(0, ConversationMessage::where('origin', 'automation')->count());
    }

    private function inscricaoSemResposta(): KeywordCampaignParticipation
    {
        $campanha = KeywordCampaign::factory()->create([
            'status' => 'ativa',
            'keywords' => ['batata'],
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'confirmation_text' => 'Inscrição confirmada! Boa sorte.',
        ]);

        $contato = Contact::factory()->create(['phone_normalized' => '5549999990001']);
        $conversa = Conversation::factory()->create(['contact_id' => $contato->id]);
        $mensagem = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'contact_id' => $contato->id,
            'direction' => 'incoming',
            'message_type' => 'text',
            'body' => 'batata',
        ]);

        return KeywordCampaignParticipation::factory()->create([
            'keyword_campaign_id' => $campanha->id,
            'contact_id' => $contato->id,
            'conversation_message_id' => $mensagem->id,
        ]);
    }
}
