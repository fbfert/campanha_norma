<?php

namespace Tests\Feature;

use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationSyncRun;
use App\Services\Conversations\ConversationSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * O eco da própria mensagem não vira uma segunda linha.
 *
 * O WhatsApp Web às vezes entrega a mensagem e ainda assim lança erro, sem
 * devolver o identificador. O serviço Node trata isso e responde com
 * `external_message_id` nulo — a linha é gravada sem id. Quando o mesmo texto
 * volta pela sincronização, a checagem de duplicidade compara identificadores,
 * não acha nada, e cria outra linha.
 *
 * O efeito não era só visual: a frase entrava duas vezes no histórico enviado
 * ao modelo, e o cálculo de "mensagens desde a última saída" passava a olhar
 * para a linha errada.
 */
class EcoDaPropriaMensagemNaoDuplicaTest extends TestCase
{
    use RefreshDatabase;

    public function test_eco_preenche_o_identificador_em_vez_de_criar_outra_linha(): void
    {
        Carbon::setTestNow('2026-08-03 19:35:53');

        $conversa = $this->conversa();

        $enviada = ConversationMessage::create([
            'conversation_id' => $conversa->id,
            'direction' => ConversationMessageDirection::Outgoing,
            'message_type' => 'text',
            'provider' => 'web',
            'external_message_id' => null,
            'body' => 'A prof Norma gostaria de saber sua opinião.',
            'status' => ConversationMessageStatus::Sent,
            'sent_at' => now(),
        ]);

        $this->sincronizar($conversa, 'msg-eco-1', 'A prof Norma gostaria de saber sua opinião.');

        $this->assertSame(1, ConversationMessage::where('conversation_id', $conversa->id)->count());
        $this->assertSame('msg-eco-1', $enviada->fresh()->external_message_id);
    }

    /**
     * Mensagem de saída que já tem identificador não é tocada: o eco dela cai
     * na checagem normal de duplicidade.
     */
    public function test_saida_com_identificador_nao_e_adotada_por_outro_eco(): void
    {
        Carbon::setTestNow('2026-08-03 19:35:53');

        $conversa = $this->conversa();

        ConversationMessage::create([
            'conversation_id' => $conversa->id,
            'direction' => ConversationMessageDirection::Outgoing,
            'message_type' => 'text',
            'provider' => 'web',
            'external_message_id' => 'msg-original',
            'body' => 'Bom dia!',
            'status' => ConversationMessageStatus::Sent,
            'sent_at' => now(),
        ]);

        $this->sincronizar($conversa, 'msg-outro', 'Bom dia!');

        $this->assertSame(2, ConversationMessage::where('conversation_id', $conversa->id)->count());
    }

    /**
     * Texto igual enviado muito depois é reenvio deliberado, e continua sendo
     * duas mensagens.
     */
    public function test_texto_igual_fora_da_janela_continua_sendo_outra_mensagem(): void
    {
        Carbon::setTestNow('2026-08-03 12:00:00');

        $conversa = $this->conversa();

        ConversationMessage::create([
            'conversation_id' => $conversa->id,
            'direction' => ConversationMessageDirection::Outgoing,
            'message_type' => 'text',
            'provider' => 'web',
            'external_message_id' => null,
            'body' => 'Bom dia!',
            'status' => ConversationMessageStatus::Sent,
            'sent_at' => now(),
        ]);

        Carbon::setTestNow('2026-08-03 19:35:53');
        $this->sincronizar($conversa, 'msg-reenvio', 'Bom dia!');

        $this->assertSame(2, ConversationMessage::where('conversation_id', $conversa->id)->count());
    }


    /**
     * O eco também entra pelo webhook ao vivo, e não só pela sincronização.
     *
     * Esta é a porta que ficou sem tratamento. A adoção foi escrita para a
     * sincronização periódica, o problema continuou acontecendo exatamente
     * igual, e demorou a ser percebido porque a correção parecia feita: na
     * conversa 1397 cada resposta da IA aparecia duas vezes para nós, e uma só
     * para o contato.
     */
    public function test_eco_pelo_webhook_tambem_preenche_o_identificador(): void
    {
        Carbon::setTestNow('2026-08-07 15:14:43');

        $conversa = $this->conversa();

        $enviada = ConversationMessage::create([
            'conversation_id' => $conversa->id,
            'direction' => ConversationMessageDirection::Outgoing,
            'message_type' => 'text',
            'provider' => 'web',
            'external_message_id' => null,
            'body' => 'A prof Norma gostaria de saber sua opinião.',
            'status' => ConversationMessageStatus::Sent,
            'sent_at' => now(),
        ]);

        \App\Jobs\ProcessIncomingMessageJob::dispatchSync([
            'event_id' => (string) \Illuminate\Support\Str::uuid(),
            'provider' => 'web',
            'connection_id' => 'principal',
            'external_message_id' => '3EB0CFDD8370B3C9D73FA4',
            'sender_phone' => '5549999990001',
            'recipient_phone' => '5549999990002',
            'message_type' => 'text',
            'text' => 'A prof Norma gostaria de saber sua opinião.',
            'sent_at' => now()->toIso8601String(),
            'is_from_me' => true,
            'is_group' => false,
            'has_media' => false,
        ]);

        $this->assertSame(
            1,
            ConversationMessage::where('conversation_id', $conversa->id)->count(),
            'O eco pelo webhook não pode virar uma segunda linha.',
        );
        $this->assertSame('3EB0CFDD8370B3C9D73FA4', $enviada->fresh()->external_message_id);
    }

    /**
     * Mensagem recebida de verdade continua entrando normalmente: a adoção vale
     * só para o que saiu daqui.
     */
    public function test_mensagem_recebida_pelo_webhook_continua_sendo_gravada(): void
    {
        $conversa = $this->conversa();

        \App\Jobs\ProcessIncomingMessageJob::dispatchSync([
            'event_id' => (string) \Illuminate\Support\Str::uuid(),
            'provider' => 'web',
            'connection_id' => 'principal',
            'external_message_id' => 'AC8AEA45DC2915E4A4DF',
            'sender_phone' => '5549999990001',
            'recipient_phone' => '5549999990002',
            'message_type' => 'text',
            'text' => 'Boa tarde',
            'is_from_me' => false,
            'is_group' => false,
            'has_media' => false,
        ]);

        $this->assertDatabaseHas('conversation_messages', [
            'body' => 'Boa tarde',
            'direction' => 'incoming',
        ]);
    }

    private function conversa(): Conversation
    {
        $contato = Contact::factory()->create(['phone_normalized' => '5549999990001']);

        return Conversation::factory()->create([
            'contact_id' => $contato->id,
            'external_chat_id' => '5549999990001@c.us',
            'provider' => 'web',
        ]);
    }

    private function sincronizar(Conversation $conversa, string $externalId, string $corpo): void
    {
        $run = ConversationSyncRun::create(['status' => 'running', 'started_at' => now()]);

        $metodo = new \ReflectionMethod(ConversationSyncService::class, 'syncMessage');
        $metodo->setAccessible(true);
        $metodo->invoke(app(ConversationSyncService::class), $run, $conversa, [
            'external_message_id' => $externalId,
            'external_chat_id' => $conversa->external_chat_id,
            'direction' => 'outgoing',
            'type' => 'chat',
            'body' => $corpo,
            'sent_at' => now()->toIso8601String(),
        ], [
            'external_chat_id' => $conversa->external_chat_id,
            'phone' => '5549999990001',
            'name' => 'Diangeli',
        ], $conversa->contact);
    }
}
