<?php

namespace Tests\Feature;

use App\Models\AiRun;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SystemSettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cards de custo de IA no painel.
 *
 * Entrada e saída aparecem separadas porque tem preço diferente e porque so a
 * separação diz onde mexer: prompt grande encarece a entrada, resposta longa
 * encarece a saída. O gasto e recalculado com o preço que esta configurado
 * agora — quem olha o painel esta decidindo se liga a automação para a base
 * inteira, e a pergunta e quanto custaria hoje.
 */
class PainelDeCustoDaIaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);

        $this->preco('ai.cost_input_per_1k', '0.002');
        $this->preco('ai.cost_output_per_1k', '0.008');
    }

    public function test_o_painel_separa_gasto_de_entrada_e_de_saida(): void
    {
        // 100.000 tokens de entrada a 0,002/mil = 0,20
        //  10.000 tokens de saída   a 0,008/mil = 0,08
        AiRun::factory()->create(['prompt_tokens' => 100000, 'completion_tokens' => 10000, 'created_at' => now()]);

        $this->actingAs($this->administrador())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Gasto com entrada')
            ->assertSee('Gasto com saída')
            ->assertSee('R$ 0,20')
            ->assertSee('R$ 0,08')
            ->assertSee('R$ 0,28');
    }

    public function test_o_gasto_acompanha_o_preco_configurado(): void
    {
        AiRun::factory()->create(['prompt_tokens' => 100000, 'completion_tokens' => 0, 'created_at' => now()]);

        $this->actingAs($this->administrador())->get(route('dashboard'))->assertSee('R$ 0,20');

        $this->preco('ai.cost_input_per_1k', '0.010');

        $this->actingAs($this->administrador())->get(route('dashboard'))->assertSee('R$ 1,00');
    }

    /**
     * Chamada do mês passado não entra no gasto do mês atual, senão o card so
     * cresce e deixa de responder quanto custou este mês.
     */
    public function test_chamada_de_outro_mes_fica_de_fora(): void
    {
        AiRun::factory()->create(['prompt_tokens' => 500000, 'completion_tokens' => 0, 'created_at' => now()->subMonthNoOverflow()->startOfMonth()]);

        $this->actingAs($this->administrador())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('R$ 1,00');
    }

    public function test_sem_chamada_nenhuma_o_painel_abre_com_zero(): void
    {
        $this->actingAs($this->administrador())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Gasto no mês')
            ->assertSee('R$ 0,00');
    }

    private function preco(string $chave, string $valor): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => $chave],
            ['group' => 'ai', 'value' => $valor, 'type' => 'string', 'is_public' => false]
        );

        app(SystemSettingService::class)->forget();
    }

    private function administrador(): User
    {
        $user = User::factory()->create(['status' => 'active', 'must_change_password' => false]);
        $user->roles()->attach(Role::query()->where('slug', 'administrador')->firstOrFail());

        return $user->refresh()->load('roles.permissions');
    }
}
