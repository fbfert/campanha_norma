<?php

namespace Tests\Feature;

use App\Enums\ConversationStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Aguardando operador" se distingue de "aguardando contato".
 *
 * As duas situações são opostas — uma é a fila de quem precisa de nós, a outra é
 * quem já foi atendido — e apareciam na lista com a mesma cor. À primeira vista
 * se confundiam, e a que exige ação era justamente a que passava despercebida.
 *
 * O filtro por situação já existia no seletor, mas no meio de outros seis
 * campos. Quem abre esta tela quer saber quem está esperando por nós, e isso
 * merece um clique.
 */
class AguardandoOperadorSeDestacaNaListaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    public function test_a_situacao_recebe_destaque_proprio_na_lista(): void
    {
        $this->conversa(ConversationStatus::WaitingOperator, 'Quem espera por nós');

        $this->actingAs($this->admin())
            ->get(route('admin.conversations.index'))
            ->assertOk()
            ->assertSee('badge awaiting-operator', false);
    }

    /**
     * A conversa que já foi atendida não pode receber o mesmo destaque: se tudo
     * chama atenção, nada chama.
     */
    public function test_aguardando_contato_nao_recebe_o_destaque(): void
    {
        $this->conversa(ConversationStatus::WaitingContact, 'Já respondida');

        $this->actingAs($this->admin())
            ->get(route('admin.conversations.index'))
            ->assertOk()
            ->assertDontSee('badge awaiting-operator', false);
    }

    public function test_o_atalho_filtra_so_quem_espera_por_nos(): void
    {
        $this->conversa(ConversationStatus::WaitingOperator, 'Precisa de resposta');
        $this->conversa(ConversationStatus::WaitingContact, 'Já respondida');

        $this->actingAs($this->admin())
            ->get(route('admin.conversations.index', ['awaiting_operator' => 1]))
            ->assertOk()
            ->assertSee('Precisa de resposta')
            ->assertDontSee('Já respondida');
    }

    public function test_sem_o_atalho_a_lista_mostra_as_duas(): void
    {
        $this->conversa(ConversationStatus::WaitingOperator, 'Precisa de resposta');
        $this->conversa(ConversationStatus::WaitingContact, 'Já respondida');

        $this->actingAs($this->admin())
            ->get(route('admin.conversations.index'))
            ->assertOk()
            ->assertSee('Precisa de resposta')
            ->assertSee('Já respondida');
    }

    private function conversa(ConversationStatus $situacao, string $nome): Conversation
    {
        return Conversation::factory()->create([
            'contact_id' => Contact::factory()->create(['name' => $nome])->id,
            'status' => $situacao,
            'last_message_at' => now(),
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['status' => 'active', 'must_change_password' => false]);
        $user->roles()->attach(Role::query()->where('slug', 'administrador')->firstOrFail());

        return $user->refresh()->load('roles.permissions');
    }
}
