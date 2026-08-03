<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SystemSettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Limiares de confiança da IA pela tela.
 *
 * O número que decide se um texto sai sem revisão humana vivia só no banco: sem
 * tela, sem auditoria, sem registro de quem mudou. E são cinco números com
 * efeitos diferentes, o que torna especialmente fácil ajustar o errado.
 */
class LimiaresDeConfiancaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    public function test_a_tela_mostra_todos_os_limiares(): void
    {
        $this->actingAs($this->userWithRole('administrador'))
            ->get(route('admin.conversation-automation.settings.edit'))
            ->assertOk()
            ->assertSee('Limiares de confiança da IA')
            ->assertSee('Autoenvio permitido a partir de')
            ->assertSee('Resposta sem aprovação a partir de')
            ->assertSee('Classificação abaixo disso pede revisão')
            ->assertSee('Extração abaixo disso pede revisão')
            ->assertSee('Marcar como baixa confiança abaixo de');
    }

    /**
     * A rede de segurança responde contornando o autoenvio, que pode estar
     * desligado de propósito. Exigir dela menos confiança que o autoenvio comum
     * seria abrir pela porta dos fundos o que a porta da frente recusa.
     */
    public function test_rede_de_seguranca_abaixo_do_autoenvio_e_recusada(): void
    {
        $this->actingAs($this->userWithRole('administrador'))
            ->put(route('admin.conversation-automation.settings.thresholds'), $this->payload([
                'ai_response_auto_send_min_confidence' => '0.90',
                'ai_response_safety_net_min_confidence' => '0.80',
            ]))
            ->assertSessionHasErrors('ai_response_safety_net_min_confidence');

        $this->assertSame('0.92', app(SystemSettingService::class)->get('ai.response.safety_net_min_confidence'));
    }

    public function test_administrador_altera_e_a_mudanca_e_auditada(): void
    {
        $this->actingAs($this->userWithRole('administrador'))
            ->put(route('admin.conversation-automation.settings.thresholds'), $this->payload([
                'ai_response_auto_send_min_confidence' => '0.85',
            ]))
            ->assertRedirect(route('admin.conversation-automation.settings.edit'))
            ->assertSessionHasNoErrors();

        $this->assertSame('0.85', app(SystemSettingService::class)->get('ai.response.auto_send_min_confidence'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'ai.thresholds_updated']);
    }

    public function test_operador_nao_altera_limiar(): void
    {
        $this->actingAs($this->userWithRole('operador'))
            ->put(route('admin.conversation-automation.settings.thresholds'), $this->payload())
            ->assertForbidden();

        $this->assertSame('0.90', app(SystemSettingService::class)->get('ai.response.auto_send_min_confidence'));
    }

    /**
     * Autoenviar com confiança menor do que a exigida para sinalizar revisão
     * significaria mandar sozinho um texto que o próprio sistema considera
     * duvidoso.
     */
    public function test_autoenvio_abaixo_do_limiar_de_revisao_e_recusado(): void
    {
        $this->actingAs($this->userWithRole('administrador'))
            ->put(route('admin.conversation-automation.settings.thresholds'), $this->payload([
                'ai_response_min_confidence' => '0.80',
                'ai_response_auto_send_min_confidence' => '0.60',
            ]))
            ->assertSessionHasErrors('ai_response_auto_send_min_confidence');

        $this->assertSame('0.90', app(SystemSettingService::class)->get('ai.response.auto_send_min_confidence'));
    }

    public function test_valor_fora_da_faixa_e_recusado(): void
    {
        $this->actingAs($this->userWithRole('administrador'))
            ->put(route('admin.conversation-automation.settings.thresholds'), $this->payload([
                'ai_min_classification_confidence' => '1.5',
            ]))
            ->assertSessionHasErrors('ai_min_classification_confidence');
    }

    public function test_o_valor_e_guardado_com_duas_casas_e_no_grupo_certo(): void
    {
        $this->actingAs($this->userWithRole('administrador'))
            ->put(route('admin.conversation-automation.settings.thresholds'), $this->payload([
                'ai_min_extraction_confidence' => '0.7',
                'analytics_low_confidence_threshold' => '0.5',
            ]));

        $extracao = SystemSetting::query()->where('key', 'ai.min_extraction_confidence')->firstOrFail();
        $this->assertSame('0.70', $extracao->value);
        $this->assertSame('ai', $extracao->group);

        $relatorio = SystemSetting::query()->where('key', 'analytics.low_confidence_threshold')->firstOrFail();
        $this->assertSame('0.50', $relatorio->value);
        $this->assertSame('analytics', $relatorio->group, 'Chave de relatório não pertence ao grupo de IA.');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'ai_min_classification_confidence' => '0.70',
            'ai_min_extraction_confidence' => '0.65',
            'ai_response_min_confidence' => '0.75',
            'ai_response_auto_send_min_confidence' => '0.90',
            'ai_response_safety_net_min_confidence' => '0.92',
            'analytics_low_confidence_threshold' => '0.70',
        ], $overrides);
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password123'),
            'status' => 'active',
            'must_change_password' => false,
        ]);

        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->firstOrFail());

        return $user->refresh()->load('roles.permissions');
    }
}
