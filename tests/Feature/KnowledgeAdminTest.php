<?php

namespace Tests\Feature;

use App\Enums\KnowledgeBaseStatus;
use App\Enums\KnowledgeDocumentStatus;
use App\Models\ConversationFlow;
use App\Models\ConversationMessage;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Role;
use App\Models\User;
use App\Services\SystemSettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Subetapa 9D: administracao da base.
 *
 * A tela de teste existe para que a base seja homologavel antes de ser ligada, e
 * nenhuma acao dela produz mensagem para ninguem.
 */
class KnowledgeAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);

        Storage::fake('local');
        Config::set('knowledge.provider', 'local');
        Config::set('knowledge.providers.local.disk', 'local');
        Config::set('knowledge.antivirus_command', '');
        app(SystemSettingService::class)->updateMany(['knowledge.antivirus_required' => '0']);
    }

    private function userWith(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', $roleSlug)->firstOrFail());

        return $user;
    }

    private function base(): KnowledgeBase
    {
        return KnowledgeBase::factory()->active()->create();
    }

    // =========================================================================
    // Permissoes
    // =========================================================================

    public function test_a_user_without_permission_cannot_see_the_bases(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.knowledge.bases.index'))->assertForbidden();
    }

    public function test_consulta_can_see_but_cannot_create(): void
    {
        $user = $this->userWith('consulta');

        $this->actingAs($user)->get(route('admin.knowledge.bases.index'))->assertOk();
        $this->actingAs($user)->get(route('admin.knowledge.bases.create'))->assertForbidden();
    }

    public function test_the_listing_offers_editing_only_to_whoever_manages_bases(): void
    {
        $base = $this->base();

        $this->actingAs($this->userWith('administrador'))
            ->get(route('admin.knowledge.bases.index'))
            ->assertOk()
            ->assertSee(route('admin.knowledge.bases.edit', $base), false);

        // Operador prepara a base, mas nao redefine o que ela e: sem o link e
        // sem a rota.
        $this->actingAs($this->userWith('operador'))
            ->get(route('admin.knowledge.bases.index'))
            ->assertOk()
            ->assertDontSee(route('admin.knowledge.bases.edit', $base), false);

        $this->actingAs($this->userWith('operador'))
            ->get(route('admin.knowledge.bases.edit', $base))
            ->assertForbidden();
    }

    public function test_an_administrador_edits_the_information_of_a_base(): void
    {
        $base = $this->base();
        $flow = ConversationFlow::factory()->create();

        $this->actingAs($this->userWith('administrador'))
            ->get(route('admin.knowledge.bases.edit', $base))
            ->assertOk()
            ->assertSee($base->name, false);

        $this->actingAs($this->userWith('administrador'))
            ->put(route('admin.knowledge.bases.update', $base), [
                'name' => 'Base revisada',
                'description' => 'Descricao revisada.',
                'purpose' => 'Finalidade revisada.',
                'usage_policy' => 'Sustenta apenas o que estiver aprovado.',
                'flow_ids' => [$flow->id],
            ])
            ->assertRedirect(route('admin.knowledge.bases.show', $base));

        $base->refresh();

        $this->assertSame('Base revisada', $base->name);
        $this->assertSame('Descricao revisada.', $base->description);
        $this->assertSame('Finalidade revisada.', $base->purpose);
        $this->assertSame('Sustenta apenas o que estiver aprovado.', $base->usage_policy);
        $this->assertSame([$flow->id], $base->flows()->pluck('conversation_flows.id')->all());
    }

    public function test_editing_a_base_does_not_change_its_situation(): void
    {
        $base = KnowledgeBase::factory()->create(['status' => KnowledgeBaseStatus::Draft]);

        $this->actingAs($this->userWith('administrador'))
            ->put(route('admin.knowledge.bases.update', $base), ['name' => 'Ainda em rascunho'])
            ->assertRedirect(route('admin.knowledge.bases.show', $base));

        // Publicar e ato separado: salvar o formulario nunca pode ligar a base.
        $this->assertSame(KnowledgeBaseStatus::Draft, $base->refresh()->status);
    }

    public function test_operador_can_upload_but_cannot_approve(): void
    {
        Queue::fake();
        $base = $this->base();
        $user = $this->userWith('operador');

        $this->actingAs($user)->get(route('admin.knowledge.documents.create', $base))->assertOk();

        $document = KnowledgeDocument::factory()->for($base, 'base')->ready()->create();

        $this->actingAs($user)
            ->post(route('admin.knowledge.documents.approve', [$base, $document]))
            ->assertForbidden();
    }

    public function test_operador_cannot_download_the_original_file(): void
    {
        $base = $this->base();
        $document = KnowledgeDocument::factory()->for($base, 'base')->approved()->create();

        $this->actingAs($this->userWith('operador'))
            ->get(route('admin.knowledge.documents.download', [$base, $document]))
            ->assertForbidden();
    }

    // =========================================================================
    // Bases
    // =========================================================================

    public function test_a_new_base_is_created_in_draft_and_is_not_retrievable(): void
    {
        $flow = ConversationFlow::factory()->create();

        $this->actingAs($this->userWith('administrador'))
            ->post(route('admin.knowledge.bases.store'), [
                'name' => 'Competencias institucionais',
                'description' => 'Conteudo oficial.',
                'flow_ids' => [$flow->id],
            ])
            ->assertRedirect();

        $base = KnowledgeBase::firstOrFail();

        $this->assertSame(KnowledgeBaseStatus::Draft, $base->status);
        $this->assertSame(0, KnowledgeBase::query()->retrievable()->count());
        $this->assertTrue($base->flows()->where('conversation_flows.id', $flow->id)->exists());
    }

    public function test_activating_a_base_is_a_separate_and_audited_act(): void
    {
        $base = KnowledgeBase::factory()->create();
        $user = $this->userWith('administrador');

        $this->actingAs($user)
            ->post(route('admin.knowledge.bases.status', $base), ['status' => KnowledgeBaseStatus::Active->value])
            ->assertRedirect();

        $this->assertSame(KnowledgeBaseStatus::Active, $base->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'knowledge_base.status_changed',
            'entity_id' => $base->id,
        ]);
    }

    public function test_the_base_slug_never_collides(): void
    {
        $user = $this->userWith('administrador');

        foreach ([1, 2] as $ignored) {
            $this->actingAs($user)->post(route('admin.knowledge.bases.store'), ['name' => 'Base oficial']);
        }

        $this->assertSame(2, KnowledgeBase::count());
        $this->assertSame(2, KnowledgeBase::query()->distinct()->count('slug'));
    }

    // =========================================================================
    // Documentos
    // =========================================================================

    public function test_uploading_a_document_through_the_screen_queues_the_indexing(): void
    {
        Queue::fake();
        $base = $this->base();

        $this->actingAs($this->userWith('administrador'))
            ->post(route('admin.knowledge.documents.store', $base), [
                'title' => 'Canais de atendimento',
                'type' => 'contact_channel',
                'file' => UploadedFile::fake()->createWithContent('canais.txt', 'O gabinete atende de segunda a sexta.'),
            ])
            ->assertRedirect();

        $document = KnowledgeDocument::firstOrFail();

        $this->assertSame(KnowledgeDocumentStatus::Processing, $document->status);
        $this->assertSame($base->id, $document->knowledge_base_id);
    }

    public function test_a_document_type_outside_the_allowlist_is_refused(): void
    {
        Queue::fake();
        $base = $this->base();

        $this->actingAs($this->userWith('administrador'))
            ->post(route('admin.knowledge.documents.store', $base), [
                'title' => 'Conversa de cidadao',
                'type' => 'citizen_conversation',
                'file' => UploadedFile::fake()->createWithContent('conversa.txt', 'Texto qualquer.'),
            ])
            ->assertSessionHasErrors('type');

        $this->assertSame(0, KnowledgeDocument::count());
    }

    public function test_a_document_that_is_not_ready_cannot_be_approved(): void
    {
        $base = $this->base();
        $document = KnowledgeDocument::factory()->for($base, 'base')->create();

        $this->actingAs($this->userWith('administrador'))
            ->post(route('admin.knowledge.documents.approve', [$base, $document]))
            ->assertSessionHasErrors('status');

        $this->assertSame(KnowledgeDocumentStatus::Draft, $document->fresh()->status);
    }

    public function test_a_document_of_another_base_is_not_reachable_through_this_one(): void
    {
        $base = $this->base();
        $other = $this->base();
        $document = KnowledgeDocument::factory()->for($other, 'base')->approved()->create();

        $this->actingAs($this->userWith('administrador'))
            ->get(route('admin.knowledge.documents.show', [$base, $document]))
            ->assertNotFound();
    }

    public function test_the_document_screen_shows_the_extracted_text_and_the_chunks(): void
    {
        $base = $this->base();
        $document = KnowledgeDocument::factory()->for($base, 'base')->approved()->create([
            'extracted_text' => 'TEXTO EXTRAIDO DO ARQUIVO OFICIAL',
        ]);
        KnowledgeChunk::factory()
            ->for($document, 'document')
            ->withContent('TRECHO INDEXADO PARA CITACAO')
            ->create(['knowledge_base_id' => $base->id]);

        $this->actingAs($this->userWith('administrador'))
            ->get(route('admin.knowledge.documents.show', [$base, $document]))
            ->assertOk()
            ->assertSee('TEXTO EXTRAIDO DO ARQUIVO OFICIAL')
            ->assertSee('TRECHO INDEXADO PARA CITACAO');
    }

    public function test_downloading_the_original_file_is_audited(): void
    {
        $base = $this->base();
        $document = KnowledgeDocument::factory()->for($base, 'base')->approved()->create([
            'disk' => 'local',
            'file_path' => 'knowledge-documents/arquivo.txt',
        ]);
        Storage::disk('local')->put($document->file_path, 'conteudo oficial');

        $this->actingAs($this->userWith('administrador'))
            ->get(route('admin.knowledge.documents.download', [$base, $document]))
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'knowledge_document.downloaded',
            'entity_id' => $document->id,
        ]);
    }

    public function test_downloading_a_missing_file_returns_not_found_instead_of_leaking_a_path(): void
    {
        $base = $this->base();
        $document = KnowledgeDocument::factory()->for($base, 'base')->approved()->create(['disk' => 'local']);

        $this->actingAs($this->userWith('administrador'))
            ->get(route('admin.knowledge.documents.download', [$base, $document]))
            ->assertNotFound();
    }

    // =========================================================================
    // Teste de busca
    // =========================================================================

    public function test_the_retrieval_screen_shows_the_excerpts_and_sends_nothing(): void
    {
        $base = $this->base();
        $document = KnowledgeDocument::factory()->for($base, 'base')->approved()->create();
        KnowledgeChunk::factory()
            ->for($document, 'document')
            ->withContent('O gabinete atende de segunda a sexta, das nove as dezessete horas.')
            ->create(['knowledge_base_id' => $base->id]);

        $this->actingAs($this->userWith('administrador'))
            ->get(route('admin.knowledge.test', [
                'query' => 'horario de atendimento do gabinete',
                'base_ids' => [$base->id],
            ]))
            ->assertOk()
            ->assertSee('gabinete atende de segunda a sexta');

        $this->assertSame(0, ConversationMessage::count(), 'A tela de teste nunca produz mensagem.');
    }

    public function test_the_retrieval_screen_reports_when_a_candidate_answer_is_not_grounded(): void
    {
        $base = $this->base();
        $document = KnowledgeDocument::factory()->for($base, 'base')->approved()->create();
        KnowledgeChunk::factory()
            ->for($document, 'document')
            ->withContent('O gabinete atende de segunda a sexta.')
            ->create(['knowledge_base_id' => $base->id]);

        $this->actingAs($this->userWith('administrador'))
            ->get(route('admin.knowledge.test', [
                'query' => 'horario de atendimento do gabinete',
                'base_ids' => [$base->id],
                'answer' => 'O gabinete atendeu 4200 pessoas no ano passado.',
            ]))
            ->assertOk()
            ->assertSee('seria bloqueado');
    }

    public function test_the_retrieval_test_is_audited(): void
    {
        $base = $this->base();

        $this->actingAs($this->userWith('administrador'))
            ->get(route('admin.knowledge.test', ['query' => 'atendimento', 'base_ids' => [$base->id]]))
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'knowledge.retrieval_tested']);
    }
}
