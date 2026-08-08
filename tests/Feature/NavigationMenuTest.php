<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Menu principal agrupado.
 *
 * O risco de reorganizar um menu não e feio: e sumir com uma tela. Uma entrada
 * removida sem substituto deixa a funcionalidade viva no roteador e inalcancável
 * na prática, e ninguém descobre até precisar dela.
 */
class NavigationMenuTest extends TestCase
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

    public function test_the_menu_is_grouped_by_task(): void
    {
        $response = $this->actingAs($this->userWith('administrador'))->get(route('dashboard'))->assertOk();

        foreach (['Atendimento', 'Pesquisa', 'Contatos', 'Envios', 'Inteligência', 'Sistema'] as $group) {
            $response->assertSee($group);
        }
    }

    /**
     * Grupos são `<details>` nativos. Se alguém trocar por uma solução com
     * JavaScript, o menu deixa de funcionar com o script bloqueado e passa a
     * depender de ordem de carregamento.
     */
    public function test_the_groups_use_native_disclosure(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->get(route('dashboard'))
            ->assertSee('<details class="nav-group"', false)
            ->assertSee('<summary>', false);
    }

    public function test_the_group_of_the_current_screen_opens_by_itself(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->get(route('admin.contacts.index'))
            ->assertOk()
            ->assertSee('open', false);
    }

    /**
     * O contador de não lidas fica no cabeçalho do grupo. Se ficasse so no link
     * interno, sumiria justamente quando o grupo esta fechado — que e quando o
     * aviso mais importa.
     */
    public function test_the_unread_badge_lives_on_the_group_header(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Atendimento');
    }

    // --- Icones ---------------------------------------------------------------

    /**
     * O sprite e desenhado uma vez por página e cada uso vira um `<use>`. Se
     * alguém trocar por uma biblioteca externa, o sistema passa a depender de
     * rede para desenhar o próprio menu.
     */
    public function test_the_icon_sprite_is_rendered_once_and_referenced_by_use(): void
    {
        $response = $this->actingAs($this->userWith('administrador'))->get(route('dashboard'))->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, substr_count((string) $html, '<g id="i-home">'), 'O sprite deve aparecer uma única vez.');
        $this->assertStringContainsString('<use href="#i-home">', (string) $html);
        $this->assertStringNotContainsString('cdn.', (string) $html);
    }

    /**
     * Icone acompanhado de rótulo em texto e decorativo: anunciar os dois faria
     * o leitor de tela repetir a mesma informação.
     */
    public function test_icons_are_hidden_from_assistive_technology(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('aria-hidden="true" focusable="false"', false);
    }

    public function test_every_icon_referenced_exists_in_the_sprite(): void
    {
        $sprite = (string) file_get_contents(resource_path('views/components/layouts/partials/icons.blade.php'));
        $menu = (string) file_get_contents(resource_path('views/components/layouts/partials/nav.blade.php'));

        preg_match_all('/<x-icon name="([a-z-]+)"/', $menu, $matches);

        $this->assertNotEmpty($matches[1]);

        foreach (array_unique($matches[1]) as $name) {
            $this->assertStringContainsString(
                '<g id="i-'.$name.'">',
                $sprite,
                "O menu usa o icone '{$name}', que não existe no sprite."
            );
        }
    }

    // --- Nenhuma tela ficou inalcancável -------------------------------------

    /**
     * As ações que sairam do menu precisam ter ganho um caminho na própria tela
     * da seção. Este e o teste que impede a reorganização de esconder algo.
     */
    public function test_creating_a_contact_is_reachable_from_the_contact_list(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->get(route('admin.contacts.index'))
            ->assertOk()
            ->assertSee(route('admin.contacts.create'), false);
    }

    public function test_importing_contacts_is_reachable_from_the_contact_list(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->get(route('admin.contacts.index'))
            ->assertOk()
            ->assertSee(route('admin.contacts.import'), false);
    }

    public function test_creating_a_batch_is_reachable_from_the_batch_list(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->get(route('admin.message-batches.index'))
            ->assertOk()
            ->assertSee(route('admin.message-batches.create'), false);
    }

    public function test_no_dead_placeholder_link_remains(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('disabled-link', false)
            ->assertDontSee('Novo envio')
            ->assertDontSee('Status dos envios');
    }

    // --- Permissão ------------------------------------------------------------

    public function test_a_query_profile_does_not_see_the_system_group_entries(): void
    {
        $this->actingAs($this->userWith('consulta'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('admin.users.index'), false)
            ->assertDontSee(route('admin.audit-logs.index'), false)
            ->assertDontSee(route('admin.maintenance.index'), false);
    }

    public function test_an_operator_sees_operational_entries_but_not_administration(): void
    {
        $this->actingAs($this->userWith('operador'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('admin.contacts.index'), false)
            ->assertDontSee(route('admin.users.index'), false);
    }

    /**
     * Toda rota citada no menu precisa existir. Um `route()` para nome
     * inexistente derruba a página inteira, e o menu aparece em todas elas.
     */
    public function test_every_route_named_in_the_menu_exists(): void
    {
        $source = file_get_contents(resource_path('views/components/layouts/partials/nav.blade.php'));

        preg_match_all("/route\('([a-z0-9\.\-]+)'\)/i", (string) $source, $matches);

        $this->assertNotEmpty($matches[1]);

        foreach (array_unique($matches[1]) as $name) {
            $this->assertNotNull(
                Route::getRoutes()->getByName($name),
                "O menu aponta para a rota '{$name}', que não existe."
            );
        }
    }
}
