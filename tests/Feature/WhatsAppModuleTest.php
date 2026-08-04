<?php

namespace Tests\Feature;

use App\Enums\ContactStatus;
use App\Enums\UserStatus;
use App\Enums\WhatsAppConnectionStatus;
use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTestMessage;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['whatsapp.service.url' => 'http://127.0.0.1:3100']);
        config(['whatsapp.service.token' => 'token-teste']);
        config(['whatsapp.test_message_enabled' => true]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    public function test_usuario_nao_autenticado_nao_acessa_conexao(): void
    {
        $this->get(route('admin.whatsapp.connection'))->assertRedirect(route('login'));
    }

    public function test_usuario_sem_permissao_nao_gerencia_conexao(): void
    {
        $reader = $this->userWithRole('consulta');

        $this->actingAs($reader)->post(route('admin.whatsapp.connect'))->assertForbidden();
    }

    public function test_administrador_visualiza_tela_e_status_e_consultado(): void
    {
        Http::fake(['http://127.0.0.1:3100/api/status' => Http::response($this->success([
            'status' => 'connected',
            'phone_number' => '5549999999999',
            'display_name' => 'Conta teste',
            'connected_at' => now()->toIso8601String(),
            'last_activity_at' => now()->toIso8601String(),
            'browser_ready' => true,
            'session_available' => true,
        ]))]);

        $admin = $this->userWithRole('administrador');

        $this->actingAs($admin)->get(route('admin.whatsapp.connection'))
            ->assertOk()
            ->assertSee('Conexão WhatsApp')
            ->assertSee('Conta teste');

        $this->assertDatabaseHas('whatsapp_connections', [
            'status' => 'connected',
            'phone_number' => '5549999999999',
        ]);
    }

    public function test_servico_indisponivel_e_tratado(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $admin = $this->userWithRole('administrador');

        $this->actingAs($admin)->get(route('admin.whatsapp.connection'))
            ->assertOk()
            ->assertSee('serviço de conexão com o WhatsApp esta indisponível');
    }

    /**
     * Não alcançar o serviço e ele demorar demais são coisas diferentes, e o
     * Guzzle entrega as duas como a mesma exceção. Chamar tudo de
     * "indisponível" manda conferir se o processo está de pé — e, quando ele
     * está de pé e só travado, a conferência dá tudo certo e a pista aponta
     * para o lugar errado.
     *
     * Aconteceu: seis sincronizações seguidas falharam dizendo "indisponível"
     * enquanto o serviço respondia normalmente. O que estava travado era a
     * página do navegador.
     */
    public function test_servico_travado_nao_e_relatado_como_indisponivel(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $admin = $this->userWithRole('administrador');

        $this->actingAs($admin)->get(route('admin.whatsapp.connection'))
            ->assertOk()
            ->assertSee('não respondeu a tempo')
            ->assertDontSee('serviço de conexão com o WhatsApp esta indisponível');
    }

    public function test_erro_de_autenticacao_interna_e_tratado(): void
    {
        Http::fake(['http://127.0.0.1:3100/api/connect' => Http::response([
            'success' => false,
            'error' => ['code' => 'UNAUTHORIZED_SERVICE_REQUEST', 'message' => 'Token inválido.'],
        ], 401)]);

        $admin = $this->userWithRole('administrador');

        $this->actingAs($admin)->post(route('admin.whatsapp.connect'))
            ->assertSessionHas('error', 'A autenticação interna com o serviço do WhatsApp falhou.')
            ->assertSessionDoesntHaveErrors();
    }

    public function test_falha_operacional_nao_e_exibida_como_erro_de_campo(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $admin = $this->userWithRole('administrador');
        $message = 'O serviço de conexão com o WhatsApp esta indisponível. Verifique o processo do Node.js na VPS.';

        $this->actingAs($admin)
            ->from(route('admin.whatsapp.connection'))
            ->post(route('admin.whatsapp.connect'))
            ->assertRedirect(route('admin.whatsapp.connection'))
            ->assertSessionHas('error', $message)
            ->assertSessionDoesntHaveErrors();

        $response = $this->actingAs($admin)
            ->withSession(['error' => $message])
            ->get(route('admin.whatsapp.connection'));

        $response->assertOk()
            ->assertDontSee('Corrija os campos destacados.');

        $this->assertSame(1, substr_count($response->getContent(), $message));
    }

    public function test_qr_code_e_exibido_somente_para_autorizado_e_nao_salvo(): void
    {
        Http::fake(['http://127.0.0.1:3100/api/qrcode' => Http::response($this->success([
            'status' => 'waiting_for_qr_scan',
            'qr_code' => 'data:image/png;base64,abc',
            'generated_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinute()->toIso8601String(),
        ]))]);

        $admin = $this->userWithRole('administrador');
        $operator = $this->userWithRole('operador');

        $this->actingAs($operator)->post(route('admin.whatsapp.qrcode'))->assertForbidden();
        $this->actingAs($admin)->post(route('admin.whatsapp.qrcode'))
            ->assertSessionHas('whatsapp_qr');

        $this->assertDatabaseMissing('whatsapp_connections', ['metadata' => 'data:image/png;base64,abc']);
    }

    public function test_exclusao_da_sessao_exige_permissao_e_senha(): void
    {
        Http::fake(['http://127.0.0.1:3100/api/session' => Http::response($this->success([
            'status' => 'disconnected',
            'message' => 'Sessão removida.',
        ]))]);

        $operator = $this->userWithRole('operador');
        $admin = $this->userWithRole('administrador');

        $this->actingAs($operator)->delete(route('admin.whatsapp.session.clear'))->assertForbidden();
        $this->actingAs($admin)->delete(route('admin.whatsapp.session.clear'), [
            'current_password' => 'senha-errada',
            'confirmation' => 'EXCLUIR SESSÃO',
        ])->assertSessionHasErrors('current_password');
    }

    public function test_envio_de_teste_exige_conexao_e_contato_valido(): void
    {
        Http::fake(['http://127.0.0.1:3100/api/status' => Http::response($this->success([
            'status' => 'disconnected',
        ]))]);

        $admin = $this->userWithRole('administrador');
        $contact = Contact::factory()->create();

        $this->actingAs($admin)->post(route('admin.whatsapp.test-message'), [
            'contact_id' => $contact->id,
            'message' => 'Teste',
        ])->assertSessionHasErrors('connection');

        $blocked = Contact::factory()->create(['status' => ContactStatus::Blocked]);
        $this->actingAs($admin)->post(route('admin.whatsapp.test-message'), [
            'contact_id' => $blocked->id,
            'message' => 'Teste',
        ])->assertSessionHasErrors('contact_id');
    }

    public function test_contato_nao_contatar_e_mensagem_vazia_sao_bloqueados(): void
    {
        $admin = $this->userWithRole('administrador');
        $contact = Contact::factory()->create(['do_not_contact' => true]);

        $this->actingAs($admin)->post(route('admin.whatsapp.test-message'), [
            'contact_id' => $contact->id,
            'message' => 'Teste',
        ])->assertSessionHasErrors('contact_id');

        $this->actingAs($admin)->post(route('admin.whatsapp.test-message'), [
            'contact_id' => $contact->id,
            'message' => '',
        ])->assertSessionHasErrors('message');
    }

    public function test_envio_de_teste_gera_request_id_e_registra_sucesso(): void
    {
        Http::fake([
            'http://127.0.0.1:3100/api/status' => Http::response($this->success([
                'status' => 'connected',
                'browser_ready' => true,
                'session_available' => true,
            ])),
            'http://127.0.0.1:3100/api/test-message' => Http::response($this->success([
                'request_id' => 'ignored-by-test',
                'status' => 'sent',
                'external_message_id' => 'msg-123',
                'sent_at' => now()->toIso8601String(),
            ])),
        ]);

        $admin = $this->userWithRole('administrador');
        $contact = Contact::factory()->create();
        WhatsAppConnection::factory()->create(['status' => WhatsAppConnectionStatus::Connected]);

        $this->actingAs($admin)->post(route('admin.whatsapp.test-message'), [
            'contact_id' => $contact->id,
            'message' => 'Teste individual',
        ])->assertRedirect();

        $test = WhatsAppTestMessage::query()->firstOrFail();
        $this->assertNotEmpty($test->request_id);
        $this->assertSame('sent', $test->status->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'whatsapp.test_message_sent']);
    }

    public function test_falha_no_envio_e_registrada(): void
    {
        Http::fake([
            'http://127.0.0.1:3100/api/status' => Http::response($this->success(['status' => 'connected'])),
            'http://127.0.0.1:3100/api/test-message' => Http::response([
                'success' => false,
                'error' => ['code' => 'SEND_FAILED', 'message' => 'Falha no envio.'],
            ], 422),
        ]);

        $admin = $this->userWithRole('administrador');
        $contact = Contact::factory()->create();

        $this->actingAs($admin)->post(route('admin.whatsapp.test-message'), [
            'contact_id' => $contact->id,
            'message' => 'Teste individual',
        ])->assertSessionHasErrors('message');

        $this->assertDatabaseHas('whatsapp_test_messages', ['status' => 'failed', 'error_code' => 'SEND_FAILED']);
    }

    public function test_eventos_sao_consultados_por_autorizado(): void
    {
        $admin = $this->userWithRole('administrador');
        $operator = $this->userWithRole('operador');

        $this->actingAs($operator)->get(route('admin.whatsapp.events'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.whatsapp.events'))->assertOk();
    }

    private function userWithRole(string $roleSlug, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'password' => Hash::make('Password123'),
            'status' => UserStatus::Active,
            'must_change_password' => false,
        ], $attributes));

        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->firstOrFail());

        return $user->refresh()->load('roles.permissions');
    }

    private function success(array $data): array
    {
        return [
            'success' => true,
            'data' => $data,
            'meta' => [
                'request_id' => 'teste',
                'timestamp' => now()->toIso8601String(),
            ],
        ];
    }
}
