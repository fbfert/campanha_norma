<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Ai\AiProviderSettings;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Configuracao do provedor de IA pela tela.
 *
 * O que estes testes protegem, acima de tudo: a credencial entra e nao volta.
 * Nao volta para a tela, nao volta para a auditoria e nao fica legivel no banco.
 */
class AiProviderSettingsTest extends TestCase
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

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'openrouter',
            'url' => 'https://openrouter.ai/api/v1',
            'model' => 'anthropic/claude-sonnet-5',
            'organization' => '',
            'key' => 'sk-segredo-de-verdade-1234',
            'timeout' => 30,
            'connect_timeout' => 5,
            'max_output_tokens' => 900,
            'temperature' => 0,
            'cost_input_per_1k' => '',
            'cost_output_per_1k' => '',
            'embedding_provider' => '',
        ], $overrides);
    }

    // --- Permissao ------------------------------------------------------------

    public function test_operator_cannot_open_the_provider_screen(): void
    {
        $this->actingAs($this->userWith('operador'))
            ->get(route('admin.ai-provider.edit'))
            ->assertForbidden();
    }

    public function test_administrator_opens_the_provider_screen(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->get(route('admin.ai-provider.edit'))
            ->assertOk()
            ->assertSee('Provedor de IA');
    }

    public function test_operator_cannot_save_the_configuration(): void
    {
        $this->actingAs($this->userWith('operador'))
            ->put(route('admin.ai-provider.update'), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseMissing('system_settings', ['key' => 'ai.key']);
    }

    // --- Gravacao -------------------------------------------------------------

    public function test_saving_stores_the_choice_and_encrypts_the_key(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->put(route('admin.ai-provider.update'), $this->payload())
            ->assertRedirect(route('admin.ai-provider.edit'));

        $this->assertDatabaseHas('system_settings', ['key' => 'ai.provider', 'value' => 'openrouter']);
        $this->assertDatabaseHas('system_settings', ['key' => 'ai.model', 'value' => 'anthropic/claude-sonnet-5']);

        $stored = SystemSetting::where('key', 'ai.key')->firstOrFail();

        $this->assertNotSame('sk-segredo-de-verdade-1234', $stored->value);
        $this->assertStringNotContainsString('sk-segredo', $stored->value);
        $this->assertSame('sk-segredo-de-verdade-1234', Crypt::decryptString($stored->value));
        $this->assertFalse((bool) $stored->is_public);
    }

    public function test_the_key_never_returns_to_the_screen(): void
    {
        $this->actingAs($this->userWith('administrador'))->put(route('admin.ai-provider.update'), $this->payload());

        $this->actingAs($this->userWith('administrador'))
            ->get(route('admin.ai-provider.edit'))
            ->assertOk()
            ->assertDontSee('sk-segredo-de-verdade-1234')
            // A dica mostra so o fim da chave, o bastante para conferir qual e.
            ->assertSee('****1234');
    }

    public function test_the_key_never_reaches_the_audit_log(): void
    {
        $this->actingAs($this->userWith('administrador'))->put(route('admin.ai-provider.update'), $this->payload());

        $entries = AuditLog::where('action', 'ai_provider.updated')->get();

        $this->assertCount(1, $entries);

        foreach ($entries as $entry) {
            $dump = json_encode([$entry->old_values, $entry->new_values]);
            $this->assertStringNotContainsString('sk-segredo', (string) $dump);
        }
    }

    public function test_an_empty_key_field_keeps_the_stored_key(): void
    {
        $admin = $this->userWith('administrador');

        $this->actingAs($admin)->put(route('admin.ai-provider.update'), $this->payload());
        $this->actingAs($admin)->put(route('admin.ai-provider.update'), $this->payload([
            'key' => '',
            'model' => 'anthropic/claude-opus-5',
        ]));

        $this->assertSame('sk-segredo-de-verdade-1234', app(AiProviderSettings::class)->key('ai.key'));
        $this->assertDatabaseHas('system_settings', ['key' => 'ai.model', 'value' => 'anthropic/claude-opus-5']);
    }

    public function test_the_stored_key_can_be_deleted_on_purpose(): void
    {
        $admin = $this->userWith('administrador');

        $this->actingAs($admin)->put(route('admin.ai-provider.update'), $this->payload());
        $this->actingAs($admin)->put(route('admin.ai-provider.update'), $this->payload([
            'key' => '',
            'forget_key' => '1',
        ]));

        $this->assertDatabaseMissing('system_settings', ['key' => 'ai.key']);
        $this->assertNull(app(AiProviderSettings::class)->key('ai.key'));
    }

    /**
     * Chave que nao decifra e chave inexistente. Fingir que existe produziria
     * uma falha de autenticacao la na frente, longe da causa real.
     */
    public function test_a_key_that_does_not_decrypt_is_treated_as_absent(): void
    {
        SystemSetting::query()->create([
            'group' => 'ai',
            'key' => 'ai.key',
            'value' => 'isto-nao-e-um-payload-cifrado',
            'type' => 'secret',
            'is_public' => false,
        ]);

        $settings = app(AiProviderSettings::class);

        $this->assertNull($settings->key('ai.key'));
        $this->assertNull($settings->hint('ai.key'));
    }

    // --- Efeito na configuracao ----------------------------------------------

    public function test_the_saved_configuration_overrides_the_environment(): void
    {
        Config::set('ai.provider', 'null');
        Config::set('ai.providers.openai.model', 'do-env');
        Config::set('ai.providers.openai.key', 'chave-do-env');

        $this->actingAs($this->userWith('administrador'))->put(route('admin.ai-provider.update'), $this->payload());

        app(AiProviderSettings::class)->applyToConfig();

        $this->assertSame('openai', config('ai.provider'));
        $this->assertSame('anthropic/claude-sonnet-5', config('ai.providers.openai.model'));
        $this->assertSame('sk-segredo-de-verdade-1234', config('ai.providers.openai.key'));
    }

    /**
     * Quem preferir manter tudo no arquivo de ambiente nao pode ser prejudicado
     * por uma tela que nunca foi preenchida.
     */
    public function test_an_empty_screen_leaves_the_environment_untouched(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.providers.openai.model', 'do-env');
        Config::set('ai.providers.openai.key', 'chave-do-env');

        app(AiProviderSettings::class)->applyToConfig();

        $this->assertSame('openai', config('ai.provider'));
        $this->assertSame('do-env', config('ai.providers.openai.model'));
        $this->assertSame('chave-do-env', config('ai.providers.openai.key'));
    }

    public function test_disabling_the_provider_stops_overriding_the_environment(): void
    {
        $admin = $this->userWith('administrador');

        $this->actingAs($admin)->put(route('admin.ai-provider.update'), $this->payload());
        $this->actingAs($admin)->put(route('admin.ai-provider.update'), $this->payload([
            'provider' => '',
            'url' => '',
            'model' => '',
            'key' => '',
        ]));

        Config::set('ai.provider', 'null');
        app(AiProviderSettings::class)->applyToConfig();

        $this->assertSame('null', config('ai.provider'));
    }

    public function test_embedding_settings_reach_the_knowledge_configuration(): void
    {
        $this->actingAs($this->userWith('administrador'))->put(route('admin.ai-provider.update'), $this->payload([
            'embedding_provider' => 'openai',
            'embedding_url' => 'https://api.openai.com/v1',
            'embedding_model' => 'text-embedding-3-small',
            'embedding_dimensions' => 1536,
            'embedding_key' => 'sk-embeddings-9999',
        ]));

        app(AiProviderSettings::class)->applyToConfig();

        $this->assertSame('openai', config('knowledge.embeddings.provider'));
        $this->assertSame('text-embedding-3-small', config('knowledge.embeddings.openai.model'));
        $this->assertSame(1536, config('knowledge.embeddings.openai.dimensions'));
        $this->assertSame('sk-embeddings-9999', config('knowledge.embeddings.openai.key'));
    }

    // --- Validacao ------------------------------------------------------------

    public function test_dimensions_above_the_column_ceiling_are_refused(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->put(route('admin.ai-provider.update'), $this->payload([
                'embedding_provider' => 'openai',
                'embedding_url' => 'https://api.openai.com/v1',
                'embedding_model' => 'gigante',
                'embedding_dimensions' => 16384,
            ]))
            ->assertSessionHasErrors('embedding_dimensions');
    }

    public function test_an_unknown_provider_is_refused(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->put(route('admin.ai-provider.update'), $this->payload(['provider' => 'fornecedor-inventado']))
            ->assertSessionHasErrors('provider');
    }

    public function test_choosing_a_provider_requires_url_and_model(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->put(route('admin.ai-provider.update'), $this->payload(['url' => '', 'model' => '']))
            ->assertSessionHasErrors(['url', 'model']);
    }

    // --- Teste de conexao -----------------------------------------------------

    public function test_the_connection_test_reports_success_without_leaking_the_key(): void
    {
        Http::fake([
            '*' => Http::response([
                'model' => 'anthropic/claude-sonnet-5',
                'choices' => [['message' => ['content' => '{"ok":true}']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
            ]),
        ]);

        $admin = $this->userWith('administrador');
        $this->actingAs($admin)->put(route('admin.ai-provider.update'), $this->payload());
        app(AiProviderSettings::class)->applyToConfig();

        $this->actingAs($admin)
            ->post(route('admin.ai-provider.test'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $entry = AuditLog::where('action', 'ai_provider.tested')->firstOrFail();
        $this->assertStringNotContainsString('sk-segredo', (string) json_encode($entry->new_values));
    }

    public function test_the_connection_test_reports_the_failure_code(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 'invalid_api_key']], 401)]);

        $admin = $this->userWith('administrador');
        $this->actingAs($admin)->put(route('admin.ai-provider.update'), $this->payload());
        app(AiProviderSettings::class)->applyToConfig();

        $this->actingAs($admin)
            ->post(route('admin.ai-provider.test'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_the_connection_test_calls_nothing_when_no_provider_is_configured(): void
    {
        Http::fake();
        Config::set('ai.provider', 'null');

        $this->actingAs($this->userWith('administrador'))
            ->post(route('admin.ai-provider.test'))
            ->assertRedirect()
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }
}
