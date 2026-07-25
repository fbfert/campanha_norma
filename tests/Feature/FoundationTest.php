<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    public function test_usuario_ativo_consegue_autenticar(): void
    {
        $user = $this->userWithRole('operador');

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_usuario_inativo_nao_consegue_autenticar(): void
    {
        $user = $this->userWithRole('operador', ['status' => UserStatus::Inactive]);

        $this->post('/login', ['email' => $user->email, 'password' => 'Password123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_usuario_bloqueado_nao_consegue_autenticar(): void
    {
        $user = $this->userWithRole('operador', ['status' => UserStatus::Blocked]);

        $this->post('/login', ['email' => $user->email, 'password' => 'Password123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_usuario_com_senha_temporaria_e_redirecionado(): void
    {
        $user = $this->userWithRole('operador', ['must_change_password' => true]);

        $this->post('/login', ['email' => $user->email, 'password' => 'Password123'])
            ->assertRedirect(route('password.force.edit'));

        $this->actingAs($user)->get(route('dashboard'))
            ->assertRedirect(route('password.force.edit'));
    }

    public function test_operador_nao_acessa_gestao_de_usuarios(): void
    {
        $operator = $this->userWithRole('operador');

        $this->actingAs($operator)->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_administrador_acessa_gestao_de_usuarios(): void
    {
        $admin = $this->userWithRole('administrador');

        $this->actingAs($admin)->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Usuarios');
    }

    public function test_administrador_pode_criar_usuario(): void
    {
        $admin = $this->userWithRole('administrador');
        $role = Role::query()->where('slug', 'operador')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Maria Operadora',
            'email' => 'MARIA@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'status' => 'active',
            'roles' => [$role->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => 'maria@example.com',
            'must_change_password' => true,
        ]);
    }

    public function test_email_duplicado_e_rejeitado(): void
    {
        $admin = $this->userWithRole('administrador');
        $existing = $this->userWithRole('operador', ['email' => 'existente@example.com']);
        $role = Role::query()->where('slug', 'consulta')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Duplicado',
            'email' => $existing->email,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'status' => 'active',
            'roles' => [$role->id],
        ])->assertSessionHasErrors('email');
    }

    public function test_administrador_nao_pode_bloquear_propria_conta(): void
    {
        $admin = $this->userWithRole('administrador');

        $this->actingAs($admin)->patch(route('admin.users.status', $admin), [
            'status' => 'blocked',
        ])->assertSessionHasErrors('status');
    }

    public function test_nao_e_possivel_remover_o_ultimo_administrador(): void
    {
        $admin = $this->userWithRole('administrador');
        $otherAdmin = $this->userWithRole('administrador');

        $this->actingAs($admin)->delete(route('admin.users.destroy', $otherAdmin))
            ->assertRedirect(route('admin.users.index'));

        $this->actingAs($admin)->delete(route('admin.users.destroy', $admin))
            ->assertSessionHasErrors('user');
    }

    public function test_configuracoes_so_podem_ser_alteradas_por_administrador(): void
    {
        $operator = $this->userWithRole('operador');
        $admin = $this->userWithRole('administrador');

        $payload = [
            'system' => [
                'name' => 'Gerenciador de Mensagens',
                'timezone' => 'America/Sao_Paulo',
                'date_format' => 'd/m/Y',
                'datetime_format' => 'd/m/Y H:i',
                'records_per_page' => 25,
            ],
        ];

        $this->actingAs($operator)->put(route('admin.settings.update'), $payload)->assertForbidden();
        $this->actingAs($admin)->put(route('admin.settings.update'), $payload)->assertRedirect();
    }

    public function test_alteracoes_importantes_geram_auditoria(): void
    {
        $admin = $this->userWithRole('administrador');
        $role = Role::query()->where('slug', 'consulta')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Auditado',
            'email' => 'auditado@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'status' => 'active',
            'roles' => [$role->id],
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created']);
    }

    public function test_usuario_consegue_alterar_a_propria_senha(): void
    {
        $user = $this->userWithRole('operador');

        $this->actingAs($user)->put(route('profile.password'), [
            'current_password' => 'Password123',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('NewPassword123', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'profile.password_changed']);
    }

    public function test_rotas_protegidas_exigem_autenticacao(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('profile.show'))->assertRedirect(route('login'));
    }

    private function userWithRole(string $roleSlug, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'password' => Hash::make('Password123'),
            'status' => UserStatus::Active,
            'must_change_password' => false,
        ], $attributes));

        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->firstOrFail());

        return $user->refresh()->load('roles');
    }
}
