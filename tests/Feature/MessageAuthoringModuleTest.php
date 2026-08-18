<?php

namespace Tests\Feature;

use App\Enums\ContactStatus;
use App\Enums\MessageBatchStatus;
use App\Enums\MessageTemplateStatus;
use App\Enums\UserStatus;
use App\Models\Contact;
use App\Models\MessageBatch;
use App\Models\MessageTemplate;
use App\Models\Role;
use App\Models\User;
use App\Services\Placeholders\MessageRendererService;
use App\Services\Placeholders\PlaceholderParserService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MessageAuthoringModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    public function test_permissoes_de_modelos(): void
    {
        $admin = $this->userWithRole('administrador');
        $operator = $this->userWithRole('operador');
        $reader = $this->userWithRole('consulta');

        $this->actingAs($admin)->get(route('admin.message-templates.index'))->assertOk();
        $this->actingAs($operator)->post(route('admin.message-templates.store'), $this->templatePayload())->assertRedirect();
        $this->actingAs($reader)->post(route('admin.message-templates.store'), $this->templatePayload(['name' => 'Consulta']))->assertForbidden();
    }

    public function test_validacao_de_modelo_e_placeholders(): void
    {
        $operator = $this->userWithRole('operador');

        $this->actingAs($operator)->post(route('admin.message-templates.store'), $this->templatePayload(['name' => '']))
            ->assertSessionHasErrors('name');
        $this->actingAs($operator)->post(route('admin.message-templates.store'), $this->templatePayload(['body' => '']))
            ->assertSessionHasErrors('body');
        $this->actingAs($operator)->post(route('admin.message-templates.store'), $this->templatePayload(['body' => 'Oi {cidade}']))
            ->assertRedirect();
        $this->actingAs($operator)->post(route('admin.message-templates.store'), $this->templatePayload(['body' => 'Oi {empresa}']))
            ->assertSessionHasErrors('body');
        $this->actingAs($operator)->post(route('admin.message-templates.store'), $this->templatePayload(['body' => 'Oi {cidade']))
            ->assertSessionHasErrors('body');
    }

    public function test_edicao_cria_versao_preserva_antiga_duplica_inativa_e_exclui(): void
    {
        $admin = $this->userWithRole('administrador');
        $template = MessageTemplate::factory()->create(['created_by' => $admin->id]);
        $this->actingAs($admin)->post(route('admin.message-templates.store'), $this->templatePayload(['name' => 'Original']));
        $created = MessageTemplate::where('name', 'Original')->firstOrFail();

        $this->actingAs($admin)->put(route('admin.message-templates.update', $created), $this->templatePayload(['body' => 'Ola {nome}.']))
            ->assertRedirect();

        $this->assertSame(2, $created->fresh()->version);
        $this->assertDatabaseHas('message_template_versions', ['message_template_id' => $created->id, 'version' => 1]);
        $this->assertDatabaseHas('message_template_versions', ['message_template_id' => $created->id, 'version' => 2]);

        $this->actingAs($admin)->post(route('admin.message-templates.duplicate', $created))->assertRedirect();
        $this->assertDatabaseHas('message_templates', ['name' => 'Primeiro contato - copia', 'status' => 'inactive']);

        $this->actingAs($admin)->patch(route('admin.message-templates.status', $created), ['status' => MessageTemplateStatus::Inactive->value])->assertRedirect();
        $this->actingAs($admin)->delete(route('admin.message-templates.destroy', $created))->assertRedirect();
        $this->assertSoftDeleted('message_templates', ['id' => $created->id]);

        $this->actingAs($admin)->patch(route('admin.message-templates.restore', $created->id))->assertRedirect();
        $this->assertNotNull($template);
    }

    public function test_parser_e_renderer(): void
    {
        $parser = app(PlaceholderParserService::class);
        $renderer = app(MessageRendererService::class);
        $contact = Contact::factory()->create([
            'name' => '<b>Mariana de Souza</b>',
            'first_name' => 'Mariana',
            'phone' => '(49) 99999-1234',
            'email' => 'mariana@example.com',
            'city' => 'Brasilia',
            'state' => 'DF',
            'country' => 'BR',
        ]);

        $parsed = $parser->parse("Oi {nome} {primeiro_nome} {telefone} {email} {cidade} {estado} {pais}\n{nome}");
        $this->assertSame(['nome', 'primeiro_nome', 'telefone', 'email', 'cidade', 'estado', 'pais'], $parsed['valid']);
        $this->assertNotEmpty($parser->parse('Oi {cidade')['malformed']);

        $result = $renderer->render("Oi {primeiro_nome}, como esta {cidade}?\nTelefone {telefone}", $contact);
        $this->assertSame("Oi Mariana, como esta Brasilia?\nTelefone (49) 99999-1234", $result['message']);
        $this->assertSame([], $result['errors']);
        $this->assertStringNotContainsString('<b>', $renderer->render('Oi {nome}', $contact)['message']);
        $this->assertSame('Texto livre', $renderer->render('Texto livre', $contact)['message']);

        // Campo sem substituto continua bloqueando: não há genérico que sirva
        // para um e-mail, e mandar a chave crua é pior que não mandar.
        $missing = $renderer->render('Retorno em {email}', Contact::factory()->create(['email' => null]));
        $this->assertNotEmpty($missing['errors']);
        $this->assertSame(['email'], $missing['missing']);

        // A cidade tem substituto desde 17/08/2026: quem entra por campanha
        // nasce sem ela, e a frase funciona sem o nome da cidade.
        $semCidade = $renderer->render('Oi {cidade}', Contact::factory()->create(['city' => null]));
        $this->assertSame('Oi sua cidade', $semCidade['message']);
        $this->assertSame([], $semCidade['missing']);
    }

    public function test_lote_cria_snapshots_exclui_nao_aptos_e_preserva_ordem(): void
    {
        $operator = $this->userWithRole('operador');
        $valid = Contact::factory()->create(['name' => 'Mariana', 'city' => 'Brasilia']);
        $inactive = Contact::factory()->create(['status' => ContactStatus::Inactive]);
        $blocked = Contact::factory()->create(['status' => ContactStatus::Blocked]);
        $doNot = Contact::factory()->create(['do_not_contact' => true]);
        $noPhone = Contact::factory()->create(['phone_normalized' => null]);
        $noCity = Contact::factory()->create(['city' => null]);
        $noEmail = Contact::factory()->create(['email' => null]);

        $this->actingAs($operator)->post(route('admin.message-batches.store'), $this->batchPayload([
            'contact_ids' => [$valid->id, $inactive->id, $blocked->id, $doNot->id, $noPhone->id, $noCity->id, $noEmail->id, $valid->id],
            'message_body' => 'Oi {primeiro_nome}, como esta {cidade}? Retorno em {email}',
        ]))->assertRedirect();

        $batch = MessageBatch::firstOrFail();
        $this->assertSame(7, $batch->selection_total);
        $this->assertSame(2, $batch->eligible_total);
        $this->assertSame(5, $batch->ineligible_total);
        $this->assertDatabaseHas('message_batch_recipients', ['message_batch_id' => $batch->id, 'contact_id' => $valid->id, 'eligibility_status' => 'eligible']);

        /*
         | Sem cidade deixou de excluir.
         |
         | Desde 17/08/2026 a cidade tem substituto: a mensagem sai dizendo
         | "sua cidade". Isto vale para o lote também, e não só para a
         | pesquisa — quem antes ficava de fora do disparo agora recebe.
         */
        $this->assertDatabaseHas('message_batch_recipients', ['message_batch_id' => $batch->id, 'contact_id' => $noCity->id, 'eligibility_status' => 'eligible']);

        // Campo sem substituto continua excluindo.
        $this->assertDatabaseHas('message_batch_recipients', ['message_batch_id' => $batch->id, 'contact_id' => $noEmail->id, 'eligibility_status' => 'excluded']);

        $positions = $batch->recipients()->pluck('random_position')->all();
        $this->assertCount(count($positions), array_unique($positions));

        $valid->update(['city' => 'Outra']);
        $this->assertSame('Brasilia', $batch->recipients()->where('contact_id', $valid->id)->firstOrFail()->contact_city_snapshot);
    }

    public function test_lote_filtrado_aleatorio_prepara_duplica_cancela_e_audita(): void
    {
        $admin = $this->userWithRole('administrador');
        Contact::factory()->count(5)->create(['city' => 'Lages', 'state' => 'SC']);
        Contact::factory()->count(2)->create(['city' => 'Brasilia', 'state' => 'DF']);

        $this->actingAs($admin)->post(route('admin.message-batches.store'), $this->batchPayload([
            'selection_type' => 'random_sample',
            'random_quantity' => 3,
            'filters' => ['city' => 'Lages'],
            'message_body' => 'Oi {primeiro_nome}.',
        ]))->assertRedirect();

        $batch = MessageBatch::firstOrFail();
        $this->assertSame(3, $batch->selection_total);
        $this->assertSame(3, $batch->eligible_total);

        $this->actingAs($admin)->post(route('admin.message-batches.randomize', $batch))->assertRedirect();
        $secondOrder = $batch->fresh()->recipients()->orderBy('contact_id')->pluck('random_position')->all();
        $this->assertCount(3, array_unique($secondOrder));

        $this->actingAs($admin)->post(route('admin.message-batches.prepare', $batch), [
            'confirmation' => 'Confirmo a criação deste lote com os destinatários e mensagens apresentados.',
        ])->assertRedirect();
        $this->assertSame(MessageBatchStatus::Ready, $batch->fresh()->status);

        $this->actingAs($admin)->put(route('admin.message-batches.update', $batch), $this->batchPayload())
            ->assertSessionHasErrors('batch');
        $this->actingAs($admin)->post(route('admin.message-batches.randomize', $batch))->assertSessionHasErrors('batch');

        $this->actingAs($admin)->post(route('admin.message-batches.duplicate', $batch))->assertRedirect();
        $copy = MessageBatch::query()->where('id', '!=', $batch->id)->firstOrFail();
        $this->assertSame('draft', $copy->status->value);
        $this->assertSame(0, $copy->recipients()->count());

        $this->actingAs($admin)->post(route('admin.message-batches.cancel', $batch), ['cancel_reason' => 'Teste concluído'])->assertRedirect();
        $this->assertSame('cancelled', $batch->fresh()->status->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'message_batch.cancelled']);
    }

    /**
     * A ordem de envio é sorteada; o texto, não.
     *
     * O sorteio de modelo por destinatário foi removido quando o sistema passou
     * a depender de template aprovado pela Meta — o motivo está em
     * `LoteUsaUmModeloSoTest`. O sorteio de **posição** ficou: ele existe para
     * que o lote não saia na ordem do cadastro, e não tem nada a ver com o
     * texto da mensagem.
     */
    public function test_a_ordem_de_envio_e_sorteada_e_o_texto_e_um_so(): void
    {
        $admin = $this->userWithRole('administrador');
        $contacts = Contact::factory()->count(6)->sequence(
            ['name' => 'Contato 1', 'first_name' => 'Contato1', 'city' => 'Lages'],
            ['name' => 'Contato 2', 'first_name' => 'Contato2', 'city' => 'Lages'],
            ['name' => 'Contato 3', 'first_name' => 'Contato3', 'city' => 'Lages'],
            ['name' => 'Contato 4', 'first_name' => 'Contato4', 'city' => 'Lages'],
            ['name' => 'Contato 5', 'first_name' => 'Contato5', 'city' => 'Lages'],
            ['name' => 'Contato 6', 'first_name' => 'Contato6', 'city' => 'Lages'],
        )->create();

        $this->actingAs($admin)->post(route('admin.message-batches.store'), $this->batchPayload([
            'name' => 'Lote - Teste',
            'message_body' => 'Oi {primeiro_nome}, de {cidade}?',
            'contact_ids' => $contacts->pluck('id')->all(),
            'random_seed' => 'a',
        ]))->assertRedirect();

        $batch = MessageBatch::firstOrFail();
        $recipients = $batch->recipients()->orderBy('contact_id')->get();

        $this->assertFalse($batch->is_campaign);
        $this->assertNull($batch->campaign_templates_snapshot);
        $this->assertSame(6, $batch->selection_total);
        $this->assertSame(6, $batch->eligible_total);
        $this->assertSame(range(1, 6), $recipients->pluck('random_position')->sort()->values()->all());

        foreach ($recipients as $recipient) {
            $this->assertNotNull($recipient->rendered_message);
            $this->assertStringNotContainsString('{', $recipient->rendered_message);
        }
    }

    public function test_permissoes_e_exportacao_de_previa(): void
    {
        $reader = $this->userWithRole('consulta');
        $admin = $this->userWithRole('administrador');
        $contact = Contact::factory()->create();

        $this->actingAs($reader)->post(route('admin.message-batches.store'), $this->batchPayload())->assertForbidden();
        $this->actingAs($admin)->post(route('admin.message-batches.store'), $this->batchPayload(['contact_ids' => [$contact->id]]))->assertRedirect();
        $batch = MessageBatch::firstOrFail();

        $this->actingAs($reader)->get(route('admin.message-batches.recipients', $batch))->assertOk();
        $this->actingAs($admin)->get(route('admin.message-batches.ineligible.export', $batch))->assertOk();
    }

    private function templatePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Primeiro contato',
            'description' => 'Modelo inicial',
            'body' => 'Oi {primeiro_nome}, como esta {cidade}?',
            'status' => 'active',
        ], $overrides);
    }

    private function batchPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Contato inicial - teste',
            'description' => 'Lote de teste',
            'message_body' => 'Oi {primeiro_nome}.',
            'selection_type' => 'manual',
            'contact_ids' => [Contact::factory()->create()->id],
            'filters' => [],
        ], $overrides);
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
}
