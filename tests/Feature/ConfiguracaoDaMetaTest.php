<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\WhatsApp\MetaSettings;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Tela de configuração da API oficial da Meta.
 *
 * A integração nasceu lendo tudo do arquivo de ambiente, e os dados que faltam
 * só existem depois que alguém termina o cadastro no painel da Meta — pessoa
 * que não é necessariamente quem tem acesso ao servidor.
 *
 * O que este teste protege é o tratamento das credenciais: token e segredo do
 * app entram cifrados e não voltam para a tela nem para a auditoria. O token de
 * verificação é exceção deliberada, porque precisa ser copiado para o painel da
 * Meta.
 */
class ConfiguracaoDaMetaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    public function test_a_tela_abre_e_lista_o_que_falta(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.whatsapp.meta-settings'))
            ->assertOk()
            ->assertSee('Meta API')
            ->assertSee('Token de acesso')
            // A lista do que falta é a resposta para "terminei?", que uma tela
            // só de campos deixa a pessoa adivinhar.
            ->assertSee('Falta para a integração funcionar');
    }

    public function test_quem_nao_tem_a_permissao_nao_entra(): void
    {
        $this->actingAs($this->userWithRole('consulta'))
            ->get(route('admin.whatsapp.meta-settings'))
            ->assertForbidden();
    }

    /**
     * O token entra cifrado e não volta.
     *
     * Guardar em claro deixaria a credencial legível para qualquer consulta ao
     * banco, e devolvê-la para a tela a colocaria no cache do navegador.
     */
    public function test_o_token_entra_cifrado_e_nao_volta_para_a_tela(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.whatsapp.meta-settings.update'), $this->payload([
                'token' => 'EAAG-token-secreto-1234',
                'app_secret' => 'segredo-do-app-9876',
            ]))
            ->assertRedirect();

        $guardado = (string) SystemSetting::query()->where('key', 'whatsapp.meta_token')->value('value');

        $this->assertNotSame('EAAG-token-secreto-1234', $guardado);
        $this->assertSame('EAAG-token-secreto-1234', Crypt::decryptString($guardado));

        $this->actingAs($this->admin())
            ->get(route('admin.whatsapp.meta-settings'))
            ->assertOk()
            ->assertDontSee('EAAG-token-secreto-1234')
            ->assertDontSee('segredo-do-app-9876')
            // A dica basta para conferir qual credencial está ali.
            ->assertSee('****1234');
    }

    /**
     * Campo em branco preserva a credencial.
     *
     * Obrigar a redigitar o token a cada ajuste de template levaria alguém a
     * deixá-lo anotado em algum lugar mais fácil de ler que este banco.
     */
    public function test_salvar_com_o_campo_em_branco_mantem_a_credencial(): void
    {
        $settings = app(MetaSettings::class);
        $settings->save(['whatsapp.meta_token' => 'token-original']);

        $this->actingAs($this->admin())
            ->put(route('admin.whatsapp.meta-settings.update'), $this->payload(['token' => '']))
            ->assertRedirect();

        $this->assertSame('token-original', app(MetaSettings::class)->secret('whatsapp.meta_token'));
    }

    public function test_marcar_apagar_remove_a_credencial(): void
    {
        app(MetaSettings::class)->save(['whatsapp.meta_token' => 'token-original']);

        $this->actingAs($this->admin())
            ->put(route('admin.whatsapp.meta-settings.update'), $this->payload([
                'token' => '',
                'forget_token' => '1',
            ]))
            ->assertRedirect();

        $this->assertNull(app(MetaSettings::class)->secret('whatsapp.meta_token'));
    }

    /** A auditoria registra a mudança sem nenhuma credencial dentro. */
    public function test_a_auditoria_nao_guarda_credencial(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.whatsapp.meta-settings.update'), $this->payload([
                'token' => 'EAAG-token-secreto-1234',
                'app_secret' => 'segredo-do-app-9876',
            ]))
            ->assertRedirect();

        $registro = (string) json_encode(
            \DB::table('audit_logs')->where('action', 'whatsapp_meta.updated')->first()
        );

        $this->assertStringNotContainsString('EAAG-token-secreto-1234', $registro);
        $this->assertStringNotContainsString('segredo-do-app-9876', $registro);
    }

    /**
     * O que a tela grava vence o arquivo de ambiente, e o provedor continua
     * lendo só `config()`.
     */
    public function test_o_banco_sobrescreve_a_configuracao_do_ambiente(): void
    {
        Config::set('whatsapp.meta.phone_number_id', 'do-env');
        Config::set('whatsapp.meta.token', 'token-do-env');

        $settings = app(MetaSettings::class);
        $settings->save([
            'whatsapp.meta_phone_number_id' => '123456789',
            'whatsapp.meta_token' => 'token-do-banco',
        ]);

        $settings->applyToConfig();

        $this->assertSame('123456789', config('whatsapp.meta.phone_number_id'));
        $this->assertSame('token-do-banco', config('whatsapp.meta.token'));
    }

    /**
     * Campo em branco no banco não apaga o do ambiente.
     *
     * Quem preferir manter tudo no `.env` não precisa fazer nada, e salvar a
     * tela sem preencher tudo não pode desligar a integração.
     */
    public function test_campo_em_branco_no_banco_nao_apaga_o_do_ambiente(): void
    {
        Config::set('whatsapp.meta.phone_number_id', 'do-env');

        app(MetaSettings::class)->save(['whatsapp.meta_phone_number_id' => '']);
        app(MetaSettings::class)->applyToConfig();

        $this->assertSame('do-env', config('whatsapp.meta.phone_number_id'));
    }

    /**
     * O identificador do número não é o telefone, e digitar o telefone ali
     * produziria uma falha de autenticação genérica muito depois.
     */
    public function test_o_identificador_do_numero_recusa_telefone(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.whatsapp.meta-settings.update'), $this->payload([
                'phone_number_id' => '+55 49 99161-3378',
            ]))
            ->assertSessionHasErrors('phone_number_id');
    }

    /** A Meta só aceita nome de template em minúsculas, dígitos e sublinhado. */
    public function test_o_nome_do_template_recusa_formato_invalido(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.whatsapp.meta-settings.update'), $this->payload([
                'invite_template' => 'Convite - Pergunta Única',
            ]))
            ->assertSessionHasErrors('invite_template');
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'phone_number_id' => '123456789',
            'verify_token' => 'combinado',
            'invite_template' => 'convite_pergunta_unica',
            'invite_language' => 'pt_BR',
        ], $extra);
    }

    private function admin(): User
    {
        return $this->userWithRole('administrador');
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->firstOrFail());

        return $user->refresh()->load('roles');
    }
}
