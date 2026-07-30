<?php

namespace Tests\Feature;

use App\Enums\ContactStatus;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class ContactModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    public function test_administrador_e_operador_acessam_contatos_e_consulta_nao_cria(): void
    {
        $admin = $this->userWithRole('administrador');
        $operator = $this->userWithRole('operador');
        $reader = $this->userWithRole('consulta');

        $this->actingAs($admin)->get(route('admin.contacts.index'))->assertOk();
        $this->actingAs($operator)->get(route('admin.contacts.index'))->assertOk();
        $this->actingAs($reader)->get(route('admin.contacts.create'))->assertForbidden();
    }

    public function test_contato_valido_e_criado_com_telefone_normalizado_e_primeiro_nome(): void
    {
        $admin = $this->userWithRole('administrador');

        $this->actingAs($admin)->post(route('admin.contacts.store'), $this->validPayload([
            'name' => '  Mariana   de Souza ',
            'first_name' => '',
            'phone' => '(49) 99999-1234',
        ]))->assertRedirect();

        $this->assertDatabaseHas('contacts', [
            'name' => 'Mariana de Souza',
            'first_name' => 'Mariana',
            'phone_normalized' => '5549999991234',
        ]);
        $this->assertDatabaseHas('contact_history', ['action' => 'created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'contact.created']);
    }

    public function test_nome_telefone_email_e_duplicidade_sao_validados(): void
    {
        $admin = $this->userWithRole('administrador');
        Contact::factory()->create(['phone_normalized' => '5549999991234']);

        $this->actingAs($admin)->post(route('admin.contacts.store'), $this->validPayload(['name' => '']))
            ->assertSessionHasErrors('name');
        $this->actingAs($admin)->post(route('admin.contacts.store'), $this->validPayload(['phone' => '123']))
            ->assertSessionHasErrors('phone');
        $this->actingAs($admin)->post(route('admin.contacts.store'), $this->validPayload(['email' => 'invalido']))
            ->assertSessionHasErrors('email');
        $this->actingAs($admin)->post(route('admin.contacts.store'), $this->validPayload(['phone' => '(49) 99999-1234']))
            ->assertSessionHasErrors('phone');
    }

    public function test_contato_pode_ser_editado_excluido_e_restauracao_verifica_duplicidade(): void
    {
        $admin = $this->userWithRole('administrador');
        $contact = Contact::factory()->create(['phone_normalized' => '5549999991234', 'phone' => '(49) 99999-1234']);

        $this->actingAs($admin)->put(route('admin.contacts.update', $contact), $this->validPayload(['city' => 'Brasilia']))
            ->assertRedirect(route('admin.contacts.show', $contact));
        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'city' => 'Brasilia']);
        $this->assertDatabaseHas('contact_history', ['contact_id' => $contact->id, 'action' => 'updated']);

        $this->actingAs($admin)->delete(route('admin.contacts.destroy', $contact))->assertRedirect();
        $this->assertSoftDeleted('contacts', ['id' => $contact->id]);

        Contact::factory()->create(['phone_normalized' => '5549999991234']);
        $this->actingAs($admin)->patch(route('admin.contacts.restore', $contact->id))
            ->assertSessionHasErrors('contact');
    }

    public function test_etiqueta_pode_ser_criada_aplicada_removida_e_sem_permissao_nao_gerencia(): void
    {
        $admin = $this->userWithRole('administrador');
        $reader = $this->userWithRole('consulta');
        $contact = Contact::factory()->create();

        $this->actingAs($admin)->post(route('admin.tags.store'), ['name' => 'Alunos', 'color' => '#176b4d', 'is_active' => 1])
            ->assertRedirect();
        $this->actingAs($admin)->post(route('admin.tags.store'), ['name' => 'Alunos', 'color' => '#176b4d'])
            ->assertSessionHasErrors('name');

        $tag = Tag::first();
        $this->actingAs($admin)->post(route('admin.contacts.bulk.tags'), ['ids' => [$contact->id], 'tag_id' => $tag->id, 'mode' => 'add'])
            ->assertRedirect();
        $this->assertDatabaseHas('contact_tag', ['contact_id' => $contact->id, 'tag_id' => $tag->id]);

        $this->actingAs($admin)->post(route('admin.contacts.bulk.tags'), ['ids' => [$contact->id], 'tag_id' => $tag->id, 'mode' => 'remove'])
            ->assertRedirect();
        $this->assertDatabaseMissing('contact_tag', ['contact_id' => $contact->id, 'tag_id' => $tag->id]);

        $this->actingAs($reader)->get(route('admin.tags.index'))->assertForbidden();
    }

    public function test_nao_contatar_exige_motivo_preserva_restricao_e_audita(): void
    {
        $operator = $this->userWithRole('operador');
        $reader = $this->userWithRole('consulta');
        $contact = Contact::factory()->create();

        $this->actingAs($operator)->patch(route('admin.contacts.do-not-contact', $contact), ['do_not_contact' => 1])
            ->assertSessionHasErrors('do_not_contact_reason');

        $this->actingAs($operator)->patch(route('admin.contacts.do-not-contact', $contact), ['do_not_contact' => 1, 'do_not_contact_reason' => 'Solicitação do titular'])
            ->assertRedirect();
        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'do_not_contact' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'contact.marked_do_not_contact']);

        $this->actingAs($reader)->patch(route('admin.contacts.do-not-contact', $contact), ['do_not_contact' => 0])
            ->assertForbidden();
    }

    public function test_busca_e_filtros_funcionam(): void
    {
        $admin = $this->userWithRole('administrador');
        $tag = Tag::factory()->create(['name' => 'Lages', 'slug' => 'lages']);
        $contact = Contact::factory()->create(['name' => 'Mariana de Souza', 'phone_normalized' => '5549999991234', 'city' => 'Lages', 'state' => 'SC', 'status' => ContactStatus::Active]);
        $contact->tags()->attach($tag->id);
        Contact::factory()->create(['name' => 'Carlos Lima', 'city' => 'Brasilia', 'status' => ContactStatus::Blocked]);

        $this->actingAs($admin)->get(route('admin.contacts.index', ['q' => 'Mariana']))->assertSee('Mariana de Souza')->assertDontSee('Carlos Lima');
        $this->actingAs($admin)->get(route('admin.contacts.index', ['q' => '(49) 99999-1234']))->assertSee('Mariana de Souza');
        $this->actingAs($admin)->get(route('admin.contacts.index', ['city' => 'Lages', 'tag_id' => $tag->id, 'status' => 'active']))->assertSee('Mariana de Souza')->assertDontSee('Carlos Lima');
    }

    public function test_importacao_csv_prevalida_identifica_duplicado_e_cria_contatos(): void
    {
        $admin = $this->userWithRole('administrador');
        Contact::factory()->create(['phone_normalized' => '5549999991234', 'do_not_contact' => true]);
        // Conteúdo de CSV, não prosa: o cabeçalho e o contrato do importador
        // (ContactImportService::COLUNAS) e os valores são lidos tal como vem.
        $csv = "nome,telefone,email,cidade,estado,etiquetas,nao_contatar,motivo_nao_contatar\n". // ortografia:ignorar
            "Mariana,(49) 99999-1234,mariana@example.com,Lages,SC,Alunos,nao,\n". // ortografia:ignorar
            "Joao,(49) 98888-1234,joao@example.com,Lages,SC,Evento,sim,Pediu bloqueio\n". // ortografia:ignorar
            ",123,email-invalido,,,Teste,nao,\n"; // ortografia:ignorar

        $file = UploadedFile::fake()->createWithContent('contatos.csv', $csv);
        $this->actingAs($admin)->post(route('admin.contacts.import.upload'), ['file' => $file])->assertRedirect();
        $import = ContactImport::first();

        $this->actingAs($admin)->post(route('admin.contacts.imports.validate', $import))->assertRedirect();
        $this->assertDatabaseHas('contact_import_rows', ['status' => 'duplicate']);
        $this->assertDatabaseHas('contact_import_rows', ['status' => 'invalid']);

        $this->actingAs($admin)->post(route('admin.contacts.imports.confirm', $import), ['duplicate_strategy' => 'ignore'])->assertRedirect();
        $this->assertDatabaseHas('contacts', ['phone_normalized' => '5549988881234', 'do_not_contact' => true]);
        $this->assertDatabaseCount('contacts', 2);
    }

    public function test_importacao_xlsx_e_aceita(): void
    {
        $admin = $this->userWithRole('administrador');
        $path = tempnam(sys_get_temp_dir(), 'contacts').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['nome', 'telefone', 'email', 'cidade', 'estado']));
        $writer->addRow(Row::fromValues(['Ana Teste', '(49) 97777-1234', 'ana@example.com', 'Lages', 'SC']));
        $writer->close();

        $file = new UploadedFile($path, 'contatos.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $this->actingAs($admin)->post(route('admin.contacts.import.upload'), ['file' => $file])->assertRedirect();

        $this->assertDatabaseHas('contact_imports', ['original_filename' => 'contatos.xlsx']);
    }

    public function test_exportacao_respeita_filtros_selecao_permissao_e_audita(): void
    {
        $admin = $this->userWithRole('administrador');
        $noExport = User::factory()->create(['password' => Hash::make('Password123')]);
        $role = Role::create(['name' => 'Sem exportação', 'slug' => 'sem-exportacao']);
        $role->permissions()->attach(Permission::where('slug', 'contacts.view')->first());
        $noExport->roles()->attach($role);

        Contact::factory()->create(['name' => 'Exportar Lages', 'city' => 'Lages']);
        Contact::factory()->create(['name' => 'Exportar Brasilia', 'city' => 'Brasilia']);

        $this->actingAs($admin)->get(route('admin.contacts.export', ['city' => 'Lages']))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'contacts.exported']);
        $this->actingAs($noExport)->get(route('admin.contacts.export'))->assertForbidden();
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password123'),
            'must_change_password' => false,
        ]);
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->firstOrFail());

        return $user->refresh()->load('roles');
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Contato Teste',
            'first_name' => '',
            'phone' => '(49) 99999-1234',
            'email' => 'contato@example.com',
            'city' => 'Lages',
            'state' => 'SC',
            'country' => 'BR',
            'source' => 'manual',
            'status' => 'active',
            'consent_status' => 'not_informed',
            'notes' => 'Observação de teste',
            'tags' => [],
        ], $overrides);
    }
}
