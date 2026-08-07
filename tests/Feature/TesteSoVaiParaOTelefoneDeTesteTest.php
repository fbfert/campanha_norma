<?php

namespace Tests\Feature;

use App\Enums\ContactStatus;
use App\Models\Contact;
use App\Models\User;
use App\Services\SystemSettingService;
use App\Services\WhatsApp\WhatsAppTestMessageService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Mensagem de teste só vai para o telefone de teste.
 *
 * Teste que sai para um eleitor não é teste: é uma mensagem de campanha mandada
 * por engano, e não há como recolher. Já aconteceu de a suíte mandar 132
 * mensagens de verdade sem ninguém perceber — naquela vez o destino era o
 * próprio número conectado, o que foi sorte do endereço, não desenho.
 */
class TesteSoVaiParaOTelefoneDeTesteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);

        Http::fake([
            '127.0.0.1:3100/api/status' => Http::response(['success' => true, 'data' => ['status' => 'connected']], 200),
            '127.0.0.1:3100/api/*' => Http::response(['success' => true, 'data' => [
                'request_id' => 'teste',
                'status' => 'sent',
                'external_message_id' => 'wamid.teste',
                'sent_at' => now()->toIso8601String(),
            ]], 200),
        ]);
    }

    public function test_o_telefone_de_teste_recebe(): void
    {
        $contato = $this->contato('5549991613378');

        $registro = app(WhatsAppTestMessageService::class)
            ->send($contato, $this->admin(), 'Mensagem de teste.', Request::create('/'));

        $this->assertSame('5549991613378', $registro->phone_snapshot);
    }

    public function test_qualquer_outro_telefone_e_recusado(): void
    {
        $contato = $this->contato('5549999998888');

        $this->expectException(ValidationException::class);

        app(WhatsAppTestMessageService::class)
            ->send($contato, $this->admin(), 'Mensagem de teste.', Request::create('/'));
    }

    /**
     * Deixar o telefone em branco libera qualquer destino, para quem quiser
     * desligar a trava sabendo o que está fazendo.
     */
    public function test_configuracao_vazia_desliga_a_trava(): void
    {
        app(SystemSettingService::class)->updateMany(['whatsapp.test_recipient_phone' => '']);

        $contato = $this->contato('5549999998888');

        $registro = app(WhatsAppTestMessageService::class)
            ->send($contato, $this->admin(), 'Mensagem de teste.', Request::create('/'));

        $this->assertSame('5549999998888', $registro->phone_snapshot);
    }

    /**
     * A comparação ignora formatação: o cadastro pode ter o telefone com
     * parênteses e traço, e recusar por causa disso seria recusar o certo.
     */
    public function test_a_comparacao_ignora_formatacao(): void
    {
        app(SystemSettingService::class)->updateMany(['whatsapp.test_recipient_phone' => '(54) 99161-3378']);

        $contato = $this->contato('54991613378');

        $registro = app(WhatsAppTestMessageService::class)
            ->send($contato, $this->admin(), 'Mensagem de teste.', Request::create('/'));

        $this->assertSame('54991613378', $registro->phone_snapshot);
    }

    private function contato(string $telefone): Contact
    {
        return Contact::factory()->create([
            'phone_normalized' => $telefone,
            'status' => ContactStatus::Active,
            'do_not_contact' => false,
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['status' => 'active', 'must_change_password' => false]);
        $user->roles()->attach(\App\Models\Role::query()->where('slug', 'administrador')->firstOrFail());

        return $user->refresh();
    }
}
