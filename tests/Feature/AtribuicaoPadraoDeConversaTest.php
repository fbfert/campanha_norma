<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SystemSettingService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Responsável padrão de toda conversa nova.
 *
 * A atribuição automática tem um efeito colateral que não e obvio: o guard do
 * autoenvio recusa conversa atribuída. Ligar uma coisa sem a outra desliga o
 * envio automático de respostas geradas sem que nada avise.
 */
class AtribuicaoPadraoDeConversaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
    }

    public function test_sem_configuracao_a_conversa_nasce_sem_responsavel(): void
    {
        $conversa = Conversation::factory()->create(['contact_id' => Contact::factory()->create()->id]);

        $this->assertNull($conversa->assigned_user_id);
        $this->assertDatabaseCount('conversation_assignments', 0);
    }

    public function test_conversa_nova_nasce_atribuida_ao_responsavel_padrao(): void
    {
        $responsavel = $this->definirResponsavelPadrao();

        $conversa = Conversation::factory()->create(['contact_id' => Contact::factory()->create()->id]);

        $this->assertSame($responsavel->id, $conversa->assigned_user_id);
        $this->assertDatabaseHas('conversation_assignments', [
            'conversation_id' => $conversa->id,
            'assigned_user_id' => $responsavel->id,
            'assigned_by' => null,
        ]);
    }

    public function test_atribuicao_explicita_prevalece_sobre_a_padrao(): void
    {
        $this->definirResponsavelPadrao();
        $outro = User::factory()->create(['status' => UserStatus::Active]);

        $conversa = Conversation::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'assigned_user_id' => $outro->id,
        ]);

        $this->assertSame($outro->id, $conversa->assigned_user_id);
    }

    public function test_responsavel_inativo_nao_recebe_conversa(): void
    {
        $inativo = User::factory()->create(['status' => UserStatus::Inactive]);
        $this->gravar('conversations.default_assignee_id', (string) $inativo->id);

        $conversa = Conversation::factory()->create(['contact_id' => Contact::factory()->create()->id]);

        $this->assertNull($conversa->assigned_user_id, 'Conversa atribuída a quem não entra no sistema fica invisível para todo mundo.');
    }

    public function test_comando_aplica_o_responsavel_as_conversas_existentes(): void
    {
        $antigas = Conversation::factory()->count(3)->create(['contact_id' => Contact::factory()->create()->id]);
        $responsavel = $this->definirResponsavelPadrao();

        $this->artisan('conversations:assign-default')->assertSuccessful();

        foreach ($antigas as $conversa) {
            $this->assertSame($responsavel->id, $conversa->fresh()->assigned_user_id);
        }
    }

    public function test_comando_so_reatribui_conversa_de_outra_pessoa_com_force(): void
    {
        $outro = User::factory()->create(['status' => UserStatus::Active]);
        $conversa = Conversation::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'assigned_user_id' => $outro->id,
        ]);

        $responsavel = $this->definirResponsavelPadrao();

        $this->artisan('conversations:assign-default')->assertSuccessful();
        $this->assertSame($outro->id, $conversa->fresh()->assigned_user_id);

        $this->artisan('conversations:assign-default', ['--force' => true])->assertSuccessful();
        $this->assertSame($responsavel->id, $conversa->fresh()->assigned_user_id);
    }

    private function definirResponsavelPadrao(): User
    {
        $responsavel = User::factory()->create(['status' => UserStatus::Active]);
        $this->gravar('conversations.default_assignee_id', (string) $responsavel->id);

        return $responsavel;
    }

    private function gravar(string $chave, string $valor): void
    {
        SystemSetting::query()->updateOrCreate(['key' => $chave], [
            'group' => 'conversations',
            'value' => $valor,
            'type' => 'string',
            'is_public' => false,
        ]);

        app(SystemSettingService::class)->forget();
    }
}
