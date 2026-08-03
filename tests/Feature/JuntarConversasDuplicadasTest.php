<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Junção de conversas duplicadas.
 *
 * Junção mal feita e pior que duplicata, porque some com a origem. Por isso o
 * comando simula por padrão, não apaga nada e arquiva em vez de excluir.
 */
class JuntarConversasDuplicadasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
    }

    public function test_sem_apply_nada_muda(): void
    {
        [$grande, $pequena] = $this->duplicadas();

        $this->artisan('conversations:merge-duplicates')->assertSuccessful();

        $this->assertSame(3, ConversationMessage::where('conversation_id', $grande->id)->count());
        $this->assertSame(1, ConversationMessage::where('conversation_id', $pequena->id)->count());
        $this->assertFalse($pequena->refresh()->is_archived);
    }

    /**
     * A conversa que fica e a que tem mais história. O primeiro critério que
     * tentei foi o chat id, e a simulação mostrou que arquivaria justamente a
     * conversa onde a pesquisa aconteceu.
     */
    public function test_fica_a_conversa_com_mais_mensagens(): void
    {
        [$grande, $pequena] = $this->duplicadas();

        $this->artisan('conversations:merge-duplicates --apply')->assertSuccessful();

        $this->assertSame(4, ConversationMessage::where('conversation_id', $grande->id)->count());
        $this->assertSame(0, ConversationMessage::where('conversation_id', $pequena->id)->count());
        $this->assertTrue($pequena->refresh()->is_archived);
        $this->assertFalse($grande->refresh()->is_archived);
    }

    /**
     * O chat id e a identidade que a sincronização reencontra. Ele acompanha a
     * conversa que fica, senão a sincronização criaria uma terceira amanhã.
     */
    public function test_o_chat_id_migra_para_a_conversa_que_fica(): void
    {
        [$grande, $pequena] = $this->duplicadas();

        $this->artisan('conversations:merge-duplicates --apply');

        $this->assertSame('chat-antigo@lid', $grande->refresh()->external_chat_id);
        $this->assertNull($pequena->refresh()->external_chat_id, 'A chave e única por provedor: não pode ficar nas duas.');
    }

    /**
     * Dois chat ids diferentes são dois chats de verdade no WhatsApp.
     */
    public function test_conversas_com_chat_id_proprio_nao_sao_juntadas(): void
    {
        $contato = Contact::factory()->create();

        $uma = $this->conversa($contato, 'chat-a@lid', 3);
        $outra = $this->conversa($contato, 'chat-b@lid', 1);

        $this->artisan('conversations:merge-duplicates --apply')->assertSuccessful();

        $this->assertSame(3, ConversationMessage::where('conversation_id', $uma->id)->count());
        $this->assertSame(1, ConversationMessage::where('conversation_id', $outra->id)->count());
        $this->assertFalse($outra->refresh()->is_archived);
    }

    public function test_contato_sem_duplicata_nao_e_tocado(): void
    {
        $contato = Contact::factory()->create();
        $unica = $this->conversa($contato, 'chat-unico@lid', 2);

        $this->artisan('conversations:merge-duplicates --apply');

        $this->assertFalse($unica->refresh()->is_archived);
    }

    /**
     * Os eventos antigos acompanham as mensagens, então a conversa esvaziada
     * ficaria completamente em branco. Quem abrisse não teria como saber que
     * ali houve conversa nem para onde ela foi.
     */
    public function test_as_duas_conversas_guardam_o_rastro_da_juncao(): void
    {
        [$grande, $pequena] = $this->duplicadas();

        $this->artisan('conversations:merge-duplicates --apply');

        $this->assertDatabaseHas('conversation_events', [
            'conversation_id' => $pequena->id,
            'event_type' => 'conversation_merged_away',
        ]);

        $this->assertDatabaseHas('conversation_events', [
            'conversation_id' => $grande->id,
            'event_type' => 'conversation_merged_in',
        ]);
    }

    /** @return array{0: Conversation, 1: Conversation} */
    private function duplicadas(): array
    {
        $contato = Contact::factory()->create();

        $pequena = $this->conversa($contato, 'chat-antigo@lid', 1);
        $grande = $this->conversa($contato, null, 3);

        return [$grande, $pequena];
    }

    private function conversa(Contact $contato, ?string $chatId, int $mensagens): Conversation
    {
        $conversa = Conversation::factory()->create([
            'contact_id' => $contato->id,
            'connection_id' => 'principal',
            'provider' => 'web',
            'external_chat_id' => $chatId,
        ]);

        ConversationMessage::factory()->count($mensagens)->create([
            'conversation_id' => $conversa->id,
            'direction' => 'incoming',
            'body' => 'mensagem',
        ]);

        return $conversa;
    }
}
