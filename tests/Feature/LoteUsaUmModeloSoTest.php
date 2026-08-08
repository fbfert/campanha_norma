<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\MessageBatch;
use App\Models\MessageTemplate;
use App\Models\Role;
use App\Models\User;
use App\Services\MessageBatches\BatchCreationService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Um lote usa um texto só.
 *
 * O sistema sorteava entre até dez modelos por lote, um por destinatário. Isso
 * fazia sentido enquanto toda mensagem era texto livre no WhatsApp Web: dava
 * para testar abordagens sem custo nenhum.
 *
 * Na API oficial cada texto é um template submetido e aprovado separadamente
 * pela Meta, e o que viaja não é a frase pronta — são as variáveis, em ordem.
 * O sorteio quebra justamente essa ordem: `placeholders_snapshot` guardava a
 * **união** das variáveis de todos os modelos sorteados, e o lote 15 ficou com
 * `[primeiro_nome, cidade]` enquanto seis dos nove modelos usavam só
 * `primeiro_nome`. Enviar assim manda duas variáveis para um template que
 * espera uma, e a cidade cai no lugar do nome ou a Meta recusa.
 *
 * Por isso o sorteio saiu da criação em vez de apenas deixar de ser usado: era
 * uma escolha de tela que corrompia o envio, e escolha de tela ninguém lembra
 * de não fazer.
 *
 * O que ficou de pé é a leitura: lote antigo continua mostrando quais modelos
 * sorteou e qual coube a cada pessoa. Apagar isso reescreveria o histórico de
 * mensagens que foram realmente enviadas.
 */
class LoteUsaUmModeloSoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    public function test_o_lote_criado_guarda_as_variaveis_de_um_modelo_so(): void
    {
        $contatos = Contact::factory()->count(2)->create(['city' => 'PONTE ALTA']);

        $lote = $this->criar([
            'message_body' => 'Oi {primeiro_nome}, sou o prof Felipe.',
            'contact_ids' => $contatos->pluck('id')->all(),
        ]);

        $this->assertFalse($lote->is_campaign);
        $this->assertNull($lote->campaign_templates_snapshot);
        $this->assertSame(['primeiro_nome'], $lote->placeholders_snapshot);
    }

    /**
     * Mandar vários modelos não sorteia mais nada: o campo deixou de existir e
     * o lote sai com o texto único que foi escrito.
     */
    public function test_mandar_varios_modelos_nao_sorteia(): void
    {
        $contatos = Contact::factory()->count(3)->create(['city' => 'PONTE ALTA']);

        $modelos = MessageTemplate::factory()->count(3)->create(['status' => 'active']);

        $lote = $this->criar([
            'message_body' => 'Oi {primeiro_nome}, posso te fazer uma pergunta?',
            'is_campaign' => '1',
            'message_template_ids' => $modelos->pluck('id')->all(),
            'contact_ids' => $contatos->pluck('id')->all(),
        ]);

        $this->assertFalse($lote->is_campaign);
        $this->assertNull($lote->campaign_templates_snapshot);

        $this->assertSame('Oi {primeiro_nome}, posso te fazer uma pergunta?', $lote->message_body_snapshot);

        foreach ($lote->recipients as $destinatario) {
            // O nome muda por pessoa; a frase em volta dele é a mesma para todos.
            $this->assertStringContainsString('posso te fazer uma pergunta?', (string) $destinatario->rendered_message);
        }
    }

    /** A tela de criar campanha não existe mais. */
    public function test_a_rota_de_campanha_nao_existe_mais(): void
    {
        $this->assertNull(Route::getRoutes()->getByName('admin.campaigns.create'));
    }

    /**
     * O histórico continua legível: lote antigo mostra os modelos sorteados.
     */
    public function test_lote_antigo_continua_mostrando_o_que_sorteou(): void
    {
        $lote = MessageBatch::factory()->create([
            'is_campaign' => true,
            'campaign_templates_snapshot' => [
                ['id' => 1, 'name' => 'nome + cidade', 'version' => 1, 'body' => 'Oi {primeiro_nome}', 'placeholders' => ['primeiro_nome']],
            ],
        ]);

        $this->actingAs($this->userWithRole('administrador'))
            ->get(route('admin.message-batches.show', $lote))
            ->assertOk()
            ->assertSee('nome + cidade');
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->firstOrFail());

        return $user->refresh()->load('roles');
    }

    /** @param array<string, mixed> $extra */
    private function criar(array $extra): MessageBatch
    {
        $dados = array_merge([
            'name' => 'Lote de um modelo só',
            'selection_type' => 'manual',
        ], $extra);

        return app(BatchCreationService::class)->create($dados, $this->userWithRole('administrador'));
    }
}
