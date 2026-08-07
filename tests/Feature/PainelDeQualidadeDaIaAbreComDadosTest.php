<?php

namespace Tests\Feature;

use App\Enums\AiRunPurpose;
use App\Models\AiRun;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O painel de qualidade da IA abre com dados dentro.
 *
 * A coluna `purpose` tem cast de enum no modelo, e o agrupamento a convertia
 * para texto direto — o que em PHP é erro fatal, não aviso. A tela caía em 500
 * assim que existisse uma única execução de IA registrada.
 *
 * O defeito passou despercebido porque o caminho vazio funcionava: sem nenhuma
 * execução, o `map` não roda e a página abre normalmente. Só quem tinha dado de
 * verdade via o erro, e era exatamente quem precisava do painel.
 */
class PainelDeQualidadeDaIaAbreComDadosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    public function test_o_painel_abre_quando_ha_execucoes_registradas(): void
    {
        AiRun::factory()->create([
            'purpose' => AiRunPurpose::GenerateReply,
            'provider' => 'openai',
            'model' => 'gpt-4.1',
            'created_at' => now()->subDay(),
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.analytics.ai-quality'))
            ->assertOk();
    }

    /**
     * O caminho vazio continua valendo: painel sem execução nenhuma abre igual.
     */
    public function test_o_painel_abre_sem_nenhuma_execucao(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.analytics.ai-quality'))
            ->assertOk();
    }

    /**
     * Mais de um propósito no mesmo período exercita o agrupamento, que é onde
     * a conversão acontecia.
     */
    public function test_propositos_diferentes_aparecem_agrupados(): void
    {
        foreach ([AiRunPurpose::GenerateReply, AiRunPurpose::Classify] as $proposito) {
            AiRun::factory()->count(2)->create([
                'purpose' => $proposito,
                'provider' => 'openai',
                'model' => 'gpt-4.1',
                'created_at' => now()->subDay(),
            ]);
        }

        $this->actingAs($this->admin())
            ->get(route('admin.analytics.ai-quality'))
            ->assertOk()
            ->assertSee('generate_reply')
            ->assertSee('classify');
    }

    private function admin(): User
    {
        $user = User::factory()->create(['status' => 'active', 'must_change_password' => false]);
        $user->roles()->attach(Role::query()->where('slug', 'administrador')->firstOrFail());

        return $user->refresh()->load('roles.permissions');
    }
}
