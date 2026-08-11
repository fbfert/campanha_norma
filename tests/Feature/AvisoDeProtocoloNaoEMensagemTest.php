<?php

namespace Tests\Feature;

use App\Enums\ConversationMessageDirection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aviso do WhatsApp não é mensagem de pessoa.
 *
 * `e2e_notification`, `notification_template` e `revoked` são gerados pelo
 * próprio WhatsApp — o "suas mensagens são protegidas com criptografia de ponta
 * a ponta", emitido quando a chave do contato muda. Ninguém escreveu nada.
 *
 * Entravam como mensagem recebida de corpo vazio. A porta foi fechada em
 * 03/08/2026 por `ConversationSyncService::PROTOCOL_TYPES`, nos dois caminhos de
 * entrada; este teste cobre a limpeza do que entrou antes.
 *
 * Dezesseis avisos ficaram em catorze conversas, e **quatro conversas existiam
 * só por causa deles** — sem contato identificado, todas com `@lid`, todas
 * paradas na fila de "Aguardando operador". Quem abria a conversa 1350
 * encontrava uma tela vazia esperando resposta para um texto que não existia.
 */
class AvisoDeProtocoloNaoEMensagemTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversa_que_so_tinha_aviso_e_removida(): void
    {
        $conversa = Conversation::factory()->create(['contact_id' => null]);
        $this->aviso($conversa, 'e2e_notification');

        $this->artisan('conversations:prune-protocol-notices', ['--aplicar' => true])->assertSuccessful();

        $this->assertSoftDeleted('conversations', ['id' => $conversa->id]);
        $this->assertSame(0, ConversationMessage::where('conversation_id', $conversa->id)->count());
    }

    /**
     * Conversa de verdade perde só a linha vazia do meio.
     */
    public function test_conversa_real_perde_so_o_aviso(): void
    {
        $conversa = Conversation::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
        ]);

        $real = $this->mensagem($conversa, 'Aqui falta médico no posto.');
        $this->aviso($conversa, 'e2e_notification');

        $this->artisan('conversations:prune-protocol-notices', ['--aplicar' => true])->assertSuccessful();

        $this->assertNotSoftDeleted('conversations', ['id' => $conversa->id]);
        $this->assertSame(1, ConversationMessage::where('conversation_id', $conversa->id)->count());
        $this->assertDatabaseHas('conversation_messages', ['id' => $real->id]);
    }

    /**
     * Os marcadores voltam a apontar para o que sobrou.
     *
     * A conversa guarda a data da última mensagem para ordenar a listagem sem
     * varrer tudo. Apagar a mensagem que esse campo aponta a deixaria ordenada
     * por um registro inexistente — e três conversas apontavam exatamente para
     * o aviso que a limpeza apaga.
     */
    public function test_a_data_da_ultima_mensagem_volta_para_a_mensagem_real(): void
    {
        $conversa = Conversation::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
        ]);

        $real = $this->mensagem($conversa, 'Aqui falta médico no posto.', '2026-07-20 10:00:00');
        $aviso = $this->aviso($conversa, 'e2e_notification', '2026-07-29 13:33:49');

        $conversa->forceFill([
            'last_message_at' => $aviso->received_at,
            'last_incoming_message_at' => $aviso->received_at,
            'last_message_direction' => ConversationMessageDirection::Incoming,
        ])->save();

        $this->artisan('conversations:prune-protocol-notices', ['--aplicar' => true])->assertSuccessful();

        $conversa->refresh();

        $this->assertSame(
            $real->received_at->format('Y-m-d H:i:s'),
            $conversa->last_message_at->format('Y-m-d H:i:s'),
        );
    }

    /** Sem --aplicar nada é gravado. */
    public function test_sem_aplicar_nada_e_gravado(): void
    {
        $conversa = Conversation::factory()->create(['contact_id' => null]);
        $this->aviso($conversa, 'e2e_notification');

        $this->artisan('conversations:prune-protocol-notices')->assertSuccessful();

        $this->assertNotSoftDeleted('conversations', ['id' => $conversa->id]);
        $this->assertSame(1, ConversationMessage::where('conversation_id', $conversa->id)->count());
    }

    private function aviso(Conversation $conversa, string $tipo, string $quando = '2026-07-29 13:33:49'): ConversationMessage
    {
        return ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'direction' => ConversationMessageDirection::Incoming,
            'message_type' => $tipo,
            'body' => null,
            'has_media' => false,
            'received_at' => $quando,
        ]);
    }

    private function mensagem(Conversation $conversa, string $texto, string $quando = '2026-07-20 10:00:00'): ConversationMessage
    {
        return ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'direction' => ConversationMessageDirection::Incoming,
            'message_type' => 'text',
            'body' => $texto,
            'received_at' => $quando,
        ]);
    }
}
