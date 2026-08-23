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
 * Tela de configuração da automação conversacional.
 *
 * Ligar o envio automático e a decisão mais sensível do sistema: a partir dela
 * um texto sai para um cidadão sem ninguém ler antes. O que precisa ser
 * garantido aqui e que a decisão seja de quem tem a permissão, fique auditada,
 * e que a tela não aceite uma combinação que promete resposta automática sem
 * poder cumprir.
 */
class ConfiguracaoDaAutomacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    public function test_administrador_abre_a_tela_e_operador_nao(): void
    {
        $this->actingAs($this->userWithRole('administrador'))
            ->get(route('admin.conversation-automation.settings.edit'))
            ->assertOk()
            ->assertSee('Envio automático liberado', false);

        $this->actingAs($this->userWithRole('operador'))
            ->get(route('admin.conversation-automation.settings.edit'))
            ->assertForbidden();
    }

    public function test_administrador_liga_a_automacao_e_a_alteracao_e_auditada(): void
    {
        $admin = $this->userWithRole('administrador');

        $this->actingAs($admin)
            ->put(route('admin.conversation-automation.settings.update'), $this->payload([
                'enabled' => 1,
                'auto_send_enabled' => 1,
            ]))
            ->assertRedirect(route('admin.conversation-automation.settings.edit'))
            ->assertSessionHasNoErrors();

        $this->assertSame('1', app(SystemSettingService::class)->get('conversation_automation.enabled'));
        $this->assertSame('1', app(SystemSettingService::class)->get('conversation_automation.auto_send_enabled'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'conversation_automation.settings_updated']);
    }

    public function test_operador_nao_grava_configuracao(): void
    {
        $this->actingAs($this->userWithRole('operador'))
            ->put(route('admin.conversation-automation.settings.update'), $this->payload(['enabled' => 1]))
            ->assertForbidden();

        $this->assertSame('0', app(SystemSettingService::class)->get('conversation_automation.enabled'));
    }

    public function test_envio_automatico_sem_automacao_ligada_e_rejeitado(): void
    {
        $this->actingAs($this->userWithRole('administrador'))
            ->put(route('admin.conversation-automation.settings.update'), $this->payload(['auto_send_enabled' => 1]))
            ->assertSessionHasErrors('auto_send_enabled');

        $this->assertSame('0', app(SystemSettingService::class)->get('conversation_automation.auto_send_enabled'));
    }

    public function test_aviso_de_automacao_sem_texto_e_rejeitado(): void
    {
        $this->actingAs($this->userWithRole('administrador'))
            ->put(route('admin.conversation-automation.settings.update'), $this->payload([
                'transparency_mode' => 'suffix',
                'transparency_text' => '',
            ]))
            ->assertSessionHasErrors('transparency_text');
    }

    public function test_janela_invalida_e_rejeitada(): void
    {
        $this->actingAs($this->userWithRole('administrador'))
            ->put(route('admin.conversation-automation.settings.update'), $this->payload(['window_start' => '25:00']))
            ->assertSessionHasErrors('window_start');
    }

    public function test_expressoes_vao_e_voltam_uma_por_linha_sem_vazio_nem_repetida(): void
    {
        $admin = $this->userWithRole('administrador');

        $this->actingAs($admin)->put(route('admin.conversation-automation.settings.update'), $this->payload([
            'yes_expressions' => "sim\n\npode  \nsim\nclaro",
        ]))->assertSessionHasNoErrors();

        $this->assertSame('sim|pode|claro', app(SystemSettingService::class)->get('conversation_automation.yes_expressions'));

        $this->actingAs($admin)
            ->get(route('admin.conversation-automation.settings.edit'))
            ->assertOk()
            ->assertSee("sim\npode\nclaro", false);
    }

    /**
     * As listas de reação são editáveis pela mesma tela, e pelo mesmo motivo
     * que as de expressão: o teclado de emoji do WhatsApp muda a cada versão, e
     * quem acompanha isso é quem lê as conversas, não quem faz o deploy.
     */
    public function test_reacoes_sao_editaveis_pela_tela(): void
    {
        $admin = $this->userWithRole('administrador');

        $this->actingAs($admin)->put(route('admin.conversation-automation.settings.update'), $this->payload([
            'positive_reactions' => "👍\n\n❤️\n👍",
            'negative_reactions' => "👎",
        ]))->assertSessionHasNoErrors();

        $this->assertSame('👍|❤️', app(SystemSettingService::class)->get('conversation_automation.positive_reactions'));
        $this->assertSame('👎', app(SystemSettingService::class)->get('conversation_automation.negative_reactions'));
    }

    /**
     * Esvaziar a lista devolve o sistema ao comportamento anterior: reagir para
     * de significar alguma coisa. É a única forma de desligar a leitura de
     * reação sem deploy, e por isso o campo não pode ser obrigatório.
     */
    public function test_lista_de_reacao_pode_ser_esvaziada(): void
    {
        $this->actingAs($this->userWithRole('administrador'))
            ->put(route('admin.conversation-automation.settings.update'), $this->payload([
                'positive_reactions' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('', app(SystemSettingService::class)->get('conversation_automation.positive_reactions'));
    }

    public function test_gravacao_preserva_grupo_tipo_e_visibilidade_da_chave(): void
    {
        $this->actingAs($this->userWithRole('administrador'))
            ->put(route('admin.conversation-automation.settings.update'), $this->payload(['enabled' => 1, 'max_automated_messages' => 5]));

        $enabled = SystemSetting::query()->where('key', 'conversation_automation.enabled')->firstOrFail();
        $this->assertSame('conversation_automation', $enabled->group);
        $this->assertSame('boolean', $enabled->type);
        $this->assertFalse((bool) $enabled->is_public);

        $max = SystemSetting::query()->where('key', 'conversation_automation.max_automated_messages')->firstOrFail();
        $this->assertSame('integer', $max->type);
        $this->assertSame('5', $max->value);
    }

    public function test_nome_das_filas_nao_e_alterado_pela_tela(): void
    {
        $this->actingAs($this->userWithRole('administrador'))
            ->put(route('admin.conversation-automation.settings.update'), $this->payload([
                'queue' => 'fila-inexistente',
                'send_queue' => 'outra-fila-inexistente',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('conversation-automation', app(SystemSettingService::class)->get('conversation_automation.queue'));
        $this->assertSame('conversation-automation-send', app(SystemSettingService::class)->get('conversation_automation.send_queue'));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'max_automated_messages' => 3,
            'default_validity_hours' => 48,
            'short_answer_max_words' => 6,
            'min_response_interval_seconds' => 0,
            'window_start' => '08:00',
            'window_end' => '20:00',
            'transparency_mode' => 'suffix',
            'transparency_text' => 'Esta e uma mensagem automática.',
            'ambiguous_behavior' => 'waiting_human',
            'no_question_behavior' => 'waiting_human',
            'thank_you_text' => 'Obrigado pela contribuição!',
            'permission_denied_text' => 'Tudo bem, obrigado pela atenção!',
            'opt_out_text' => 'Você não receberá mais mensagens.',
            'yes_expressions' => "sim\npode",
            'no_expressions' => "não\nagora não",
            'opt_out_expressions' => "sair\nparar",
            'positive_reactions' => "👍",
            'negative_reactions' => "👎",
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
