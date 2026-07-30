<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Manual de uso e mapa mental.
 *
 * O risco de uma documentação dentro do sistema não e estar feia: e envelhecer
 * sem ninguém perceber. Um manual que afirma "o limite e três mensagens" vira
 * mentira no dia em que alguém muda a configuração, e mentira em manual e pior
 * do que ausência de manual, porque quem le confia.
 */
class ManualTest extends TestCase
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

    public function test_the_manual_opens(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->get(route('manual.index'))
            ->assertOk()
            ->assertSee('Para que serve este sistema');
    }

    public function test_the_mind_map_opens(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->get(route('manual.mind-map'))
            ->assertOk()
            ->assertSee('Como ler este mapa');
    }

    /**
     * Documentação atrás de permissão esconde o manual justamente de quem esta
     * começando. O perfil mais restrito precisa conseguir ler.
     */
    public function test_the_most_restricted_profile_can_read_the_manual(): void
    {
        $this->actingAs($this->userWith('consulta'))
            ->get(route('manual.index'))
            ->assertOk();

        $this->actingAs($this->userWith('consulta'))
            ->get(route('manual.mind-map'))
            ->assertOk();
    }

    public function test_the_manual_requires_login(): void
    {
        $this->get(route('manual.index'))->assertRedirect(route('login'));
        $this->get(route('manual.mind-map'))->assertRedirect(route('login'));
    }

    public function test_the_menu_offers_both_screens(): void
    {
        $this->actingAs($this->userWith('consulta'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('manual.index'), false)
            ->assertSee(route('manual.mind-map'), false);
    }

    // --- O manual não pode mentir --------------------------------------------

    /**
     * Este e o teste que importa. O limite mostrado no manual tem de ser o
     * limite configurado, e não um número escrito no texto no dia em que a tela
     * foi feita.
     */
    public function test_the_manual_shows_the_configured_limits_and_not_a_written_number(): void
    {
        SystemSetting::query()
            ->where('key', 'conversation_automation.max_automated_messages')
            ->update(['value' => '7']);
        SystemSetting::query()
            ->where('key', 'analytics.minimum_cell_size')
            ->update(['value' => '11']);
        Cache::flush();

        // Procura o valor no lugar exato onde ele e apresentado. `assertSee('7')`
        // passaria com qualquer sete perdido na página.
        $this->actingAs($this->userWith('administrador'))
            ->get(route('manual.index'))
            ->assertOk()
            ->assertSee('<strong>7</strong>', false)
            ->assertSee('menos de <strong>11</strong> pessoas', false);
    }

    /**
     * Automação e envio automático são dois interruptores separados, e o manual
     * explica isso. Se o estado mostrado não acompanhar a configuração, a
     * explicação perde o valor.
     */
    public function test_the_manual_reports_the_current_state_of_the_switches(): void
    {
        $user = $this->userWith('administrador');

        $this->actingAs($user)->get(route('manual.index'))->assertOk()->assertSee('Desligada');

        SystemSetting::query()->where('key', 'conversation_automation.enabled')->update(['value' => '1']);
        Cache::flush();

        $this->actingAs($user)->get(route('manual.index'))->assertOk()->assertSee('Ligada');
    }

    /**
     * O aviso de mensagem automática e configurável. O manual mostra o texto
     * que esta valendo, e não uma copia dele.
     */
    public function test_the_manual_shows_the_configured_transparency_notice(): void
    {
        SystemSetting::query()
            ->where('key', 'conversation_automation.transparency_text')
            ->update(['value' => 'Aviso escolhido pela equipe.']);
        Cache::flush();

        $this->actingAs($this->userWith('administrador'))
            ->get(route('manual.index'))
            ->assertOk()
            ->assertSee('Aviso escolhido pela equipe.');
    }

    // --- Manual e mapa contam a mesma história -------------------------------

    /**
     * O roteiro vive no controlador e alimenta as duas telas. Se uma seção for
     * acrescentada la sem ganhar texto no manual, o link do mapa cai num
     * pedaço de página que não existe - e ninguém descobre, porque ancora
     * quebrada não da erro.
     */
    public function test_every_branch_of_the_mind_map_lands_somewhere_in_the_manual(): void
    {
        $user = $this->userWith('administrador');

        $map = (string) $this->actingAs($user)->get(route('manual.mind-map'))->assertOk()->getContent();
        $manual = (string) $this->actingAs($user)->get(route('manual.index'))->assertOk()->getContent();

        preg_match_all('/'.preg_quote(route('manual.index'), '/').'#([a-z-]+)/', $map, $matches);

        $this->assertNotEmpty($matches[1], 'O mapa mental precisa apontar para as seções do manual.');

        foreach (array_unique($matches[1]) as $anchor) {
            $this->assertStringContainsString(
                'id="'.$anchor.'"',
                $manual,
                "O mapa aponta para a seção '{$anchor}', que não existe no manual."
            );
        }
    }

    /**
     * O índice do manual e gerado do mesmo roteiro. Mesma verificação, outro
     * caminho: nenhum item do índice pode levar a lugar nenhum.
     */
    public function test_every_entry_in_the_table_of_contents_has_a_section(): void
    {
        $manual = (string) $this->actingAs($this->userWith('administrador'))
            ->get(route('manual.index'))
            ->assertOk()
            ->getContent();

        preg_match_all('/<a href="#([a-z-]+)"/', $manual, $matches);

        $this->assertNotEmpty($matches[1]);

        foreach (array_unique($matches[1]) as $anchor) {
            $this->assertStringContainsString('id="'.$anchor.'"', $manual);
        }
    }

    /**
     * O mapa e desenhado com CSS sobre uma lista aninhada. Se alguém trocar por
     * imagem ou biblioteca, ele deixa de ser lido por leitor de tela e passa a
     * depender de rede.
     */
    public function test_the_mind_map_is_a_real_nested_list_and_not_an_image(): void
    {
        $map = (string) $this->actingAs($this->userWith('administrador'))
            ->get(route('manual.mind-map'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<ul class="mindmap-branches">', $map);
        $this->assertStringNotContainsString('<img', $map);
        $this->assertStringNotContainsString('<canvas', $map);
        $this->assertStringNotContainsString('cdn.', $map);
    }

    public function test_every_icon_used_by_the_manual_exists_in_the_sprite(): void
    {
        $sprite = (string) file_get_contents(resource_path('views/components/layouts/partials/icons.blade.php'));

        foreach (['manual/index.blade.php', 'manual/mind-map.blade.php'] as $view) {
            preg_match_all('/<x-icon name="([a-z-]+)"/', (string) file_get_contents(resource_path('views/'.$view)), $matches);

            $this->assertNotEmpty($matches[1]);

            foreach (array_unique($matches[1]) as $name) {
                $this->assertStringContainsString(
                    '<g id="i-'.$name.'">',
                    $sprite,
                    "O manual usa o icone '{$name}', que não existe no sprite."
                );
            }
        }
    }
}
