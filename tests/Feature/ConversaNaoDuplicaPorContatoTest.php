<?php

namespace Tests\Feature;

use App\Enums\ConversationStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Services\Conversations\ConversationResolverService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Um contato, uma conversa.
 *
 * O sistema tem duas portas que criam conversa, e elas identificam por chaves
 * diferentes: a sincronização por `provider + external_chat_id`, e o resolvedor
 * — webhook, automação, lote — por `connection_id + contact_id`, sem chat id.
 * Enquanto as duas concordam não ha problema; quando uma não reconhece o que a
 * outra criou, o mesmo contato passa a ter duas conversas, e metade do
 * histórico fica invisível em cada tela.
 *
 * Cinco contatos ficaram assim em produção.
 */
class ConversaNaoDuplicaPorContatoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
    }

    /**
     * Conversa criada pela sincronização, com chat id, precisa ser reaproveitada
     * pelo resolvedor — senão a resposta automática abre uma segunda conversa.
     */
    public function test_o_resolvedor_reaproveita_a_conversa_da_sincronizacao(): void
    {
        $contato = Contact::factory()->create();

        $daSincronizacao = Conversation::factory()->create([
            'contact_id' => $contato->id,
            'connection_id' => 'principal',
            'provider' => 'web',
            'external_chat_id' => '20993967976518@lid',
        ]);

        $resolvida = app(ConversationResolverService::class)->resolve($contato, 'principal', true, $contato->phone_normalized);

        $this->assertSame($daSincronizacao->id, $resolvida->id);
        $this->assertSame(1, Conversation::query()->where('contact_id', $contato->id)->count());
    }

    /**
     * Conversa arquivada volta a ser usada em vez de virar uma segunda: o
     * contato voltou a falar, e a conversa dele e a mesma.
     */
    public function test_conversa_arquivada_e_reaberta_em_vez_de_duplicada(): void
    {
        $contato = Contact::factory()->create();

        $arquivada = Conversation::factory()->create([
            'contact_id' => $contato->id,
            'connection_id' => 'principal',
            'is_archived' => true,
            'status' => ConversationStatus::Archived,
        ]);

        $resolvida = app(ConversationResolverService::class)->resolve($contato, 'principal', true, $contato->phone_normalized);

        $this->assertSame(1, Conversation::query()->where('contact_id', $contato->id)->count(), 'Conversa arquivada não pode virar uma segunda conversa.');
        $this->assertSame($arquivada->id, $resolvida->id);
    }

    /**
     * Conversa encerrada e decisão de quem operou: reabrir por conta própria
     * seria desfazer o encerramento. Aqui uma segunda conversa e o certo.
     */
    public function test_conversa_encerrada_da_lugar_a_uma_nova(): void
    {
        $contato = Contact::factory()->create();

        Conversation::factory()->create([
            'contact_id' => $contato->id,
            'connection_id' => 'principal',
            'status' => ConversationStatus::Closed,
        ]);

        app(ConversationResolverService::class)->resolve($contato, 'principal', true, $contato->phone_normalized);

        $this->assertSame(2, Conversation::query()->where('contact_id', $contato->id)->count());
    }
}
