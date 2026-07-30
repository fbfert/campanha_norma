<?php

namespace Tests\Feature;

use App\Enums\ContactStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Iniciar uma conversa a partir de um contato.
 *
 * A tela nao envia nada: ela abre a conversa e leva para onde a mensagem e
 * escrita. O que precisa ser garantido aqui e que ela nao abra caminho para
 * falar com quem pediu para nao ser contatado - a lista de contatos e o lugar
 * mais provavel de esse pedido ser esquecido.
 */
class StartConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    private function userWith(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', $roleSlug)->firstOrFail());

        return $user;
    }

    private function contact(array $attributes = []): Contact
    {
        return Contact::factory()->create(array_merge([
            'status' => ContactStatus::Active,
            'do_not_contact' => false,
            'phone_normalized' => '5511999990000',
        ], $attributes));
    }

    // --- Acesso as duas acoes da tela de conversas ---------------------------

    public function test_the_conversation_list_offers_refreshing_and_starting(): void
    {
        $this->actingAs($this->userWith('operador'))
            ->get(route('admin.conversations.index'))
            ->assertOk()
            ->assertSee('Atualizar conversas')
            ->assertSee(route('admin.conversations.create'), false);
    }

    /**
     * Quem so consulta nao inicia conversa. O botao nao pode aparecer, senao
     * leva a um 403 depois do clique.
     */
    public function test_a_query_profile_does_not_see_the_start_button(): void
    {
        $this->actingAs($this->userWith('consulta'))
            ->get(route('admin.conversations.index'))
            ->assertOk()
            ->assertDontSee(route('admin.conversations.create'), false);
    }

    public function test_a_query_profile_cannot_open_the_start_screen(): void
    {
        $this->actingAs($this->userWith('consulta'))
            ->get(route('admin.conversations.create'))
            ->assertForbidden();
    }

    // --- A tela de escolha ----------------------------------------------------

    public function test_the_start_screen_lists_contacts_and_offers_creating_one(): void
    {
        $this->contact(['name' => 'Joana Ribeiro']);

        $this->actingAs($this->userWith('operador'))
            ->get(route('admin.conversations.create'))
            ->assertOk()
            ->assertSee('Joana Ribeiro')
            ->assertSee(route('admin.contacts.create'), false);
    }

    public function test_the_search_field_finds_by_name_and_by_phone(): void
    {
        $this->contact(['name' => 'Joana Ribeiro', 'phone_normalized' => '5511911112222']);
        $this->contact(['name' => 'Carlos Souza', 'phone_normalized' => '5511933334444']);

        $user = $this->userWith('operador');

        $this->actingAs($user)
            ->get(route('admin.conversations.create', ['q' => 'Joana']))
            ->assertOk()
            ->assertSee('Joana Ribeiro')
            ->assertDontSee('Carlos Souza');

        $this->actingAs($user)
            ->get(route('admin.conversations.create', ['q' => '3333']))
            ->assertOk()
            ->assertSee('Carlos Souza')
            ->assertDontSee('Joana Ribeiro');
    }

    /**
     * Por padrao a lista mostra so quem pode receber. Pedindo para ver todos, o
     * impedido aparece com o motivo escrito, e nao simplesmente sumido.
     */
    public function test_blocked_contacts_are_hidden_by_default_and_shown_with_the_reason_on_request(): void
    {
        $this->contact(['name' => 'Marta Blocked', 'do_not_contact' => true]);

        $user = $this->userWith('operador');

        $this->actingAs($user)
            ->get(route('admin.conversations.create'))
            ->assertOk()
            ->assertDontSee('Marta Blocked');

        $this->actingAs($user)
            ->get(route('admin.conversations.create', ['only_eligible' => '0']))
            ->assertOk()
            ->assertSee('Marta Blocked')
            ->assertSee('Marcado como nao contatar');
    }

    // --- Abrir a conversa -----------------------------------------------------

    public function test_starting_a_conversation_opens_it_assigned_to_whoever_started_it(): void
    {
        $contact = $this->contact();
        $user = $this->userWith('operador');

        $response = $this->actingAs($user)->post(route('admin.conversations.store'), ['contact_id' => $contact->id]);

        $conversation = Conversation::where('contact_id', $contact->id)->firstOrFail();

        $response->assertRedirect(route('admin.conversations.show', $conversation));
        $this->assertSame($user->id, $conversation->assigned_user_id);
    }

    /**
     * Nada e enviado ao abrir a conversa. Se uma mensagem nascesse aqui, ela
     * sairia sem ninguem ter escrito nem revisado texto nenhum.
     */
    public function test_starting_a_conversation_sends_nothing(): void
    {
        $contact = $this->contact();

        $this->actingAs($this->userWith('operador'))
            ->post(route('admin.conversations.store'), ['contact_id' => $contact->id]);

        $conversation = Conversation::where('contact_id', $contact->id)->firstOrFail();

        $this->assertSame(0, $conversation->messages()->count());
    }

    /**
     * Duas pessoas clicando no mesmo contato nao podem produzir duas conversas
     * paralelas: a segunda cai na que ja existe.
     */
    public function test_starting_twice_reuses_the_open_conversation(): void
    {
        $contact = $this->contact();
        $user = $this->userWith('operador');

        $this->actingAs($user)->post(route('admin.conversations.store'), ['contact_id' => $contact->id]);
        $this->actingAs($user)->post(route('admin.conversations.store'), ['contact_id' => $contact->id]);

        $this->assertSame(1, Conversation::where('contact_id', $contact->id)->count());
    }

    public function test_a_contact_marked_do_not_contact_cannot_be_started(): void
    {
        $contact = $this->contact(['do_not_contact' => true]);

        $this->actingAs($this->userWith('operador'))
            ->post(route('admin.conversations.store'), ['contact_id' => $contact->id])
            ->assertSessionHasErrors('contact_id');

        $this->assertSame(0, Conversation::where('contact_id', $contact->id)->count());
    }

    public function test_an_inactive_contact_cannot_be_started(): void
    {
        $contact = $this->contact(['status' => ContactStatus::Inactive]);

        $this->actingAs($this->userWith('operador'))
            ->post(route('admin.conversations.store'), ['contact_id' => $contact->id])
            ->assertSessionHasErrors('contact_id');

        $this->assertSame(0, Conversation::where('contact_id', $contact->id)->count());
    }

    public function test_a_contact_without_a_valid_phone_cannot_be_started(): void
    {
        $contact = $this->contact(['phone_normalized' => null]);

        $this->actingAs($this->userWith('operador'))
            ->post(route('admin.conversations.store'), ['contact_id' => $contact->id])
            ->assertSessionHasErrors('contact_id');

        $this->assertSame(0, Conversation::where('contact_id', $contact->id)->count());
    }

    public function test_a_query_profile_cannot_start_a_conversation(): void
    {
        $contact = $this->contact();

        $this->actingAs($this->userWith('consulta'))
            ->post(route('admin.conversations.store'), ['contact_id' => $contact->id])
            ->assertForbidden();

        $this->assertSame(0, Conversation::where('contact_id', $contact->id)->count());
    }

    /**
     * "nova" precisa ser rota propria, e nao ser lida como identificador de
     * conversa. Se a ordem das rotas mudar, isto quebra antes de alguem
     * descobrir na tela.
     */
    public function test_the_start_route_is_not_swallowed_by_the_show_route(): void
    {
        $this->actingAs($this->userWith('operador'))
            ->get('/admin/conversations/nova')
            ->assertOk()
            ->assertSee('Com quem voce quer falar?');
    }
}
