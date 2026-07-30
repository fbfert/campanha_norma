<?php

namespace Tests\Feature;

use App\Enums\KnowledgeDocumentStatus;
use App\Jobs\IndexKnowledgeDocumentJob;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeRetrieval;
use App\Models\KnowledgeRetrievalChunk;
use App\Models\ReplySuggestionCitation;
use App\Services\SystemSettingService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Subetapa 9D: comandos de operação.
 *
 * Nenhum deles aprova documento nem chama provedor externo. Reconciliar estado e
 * trabalho de manutenção; decidir o que e conteúdo oficial não e.
 */
class KnowledgeCommandsTest extends TestCase
{
    use RefreshDatabase;

    private KnowledgeBase $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
        Storage::fake('local');

        $this->base = KnowledgeBase::factory()->active()->create();
    }

    // --- knowledge:diagnose --------------------------------------------------

    public function test_diagnose_reports_the_configuration_without_calling_any_provider(): void
    {
        $this->artisan('knowledge:diagnose')
            ->expectsOutputToContain('Recuperação ligada: não')
            ->expectsOutputToContain('Estratégia: lexical')
            ->assertSuccessful();
    }

    public function test_diagnose_warns_when_a_document_file_is_missing(): void
    {
        KnowledgeDocument::factory()->for($this->base, 'base')->approved()->create([
            'disk' => 'local',
            'file_path' => 'knowledge-documents/sumiu.txt',
        ]);

        $this->artisan('knowledge:diagnose')
            ->expectsOutputToContain('com arquivo ausente')
            ->assertSuccessful();
    }

    // --- knowledge:index -----------------------------------------------------

    public function test_index_queues_the_selected_documents(): void
    {
        Queue::fake();
        $document = KnowledgeDocument::factory()->for($this->base, 'base')->ready()->create();

        $this->artisan('knowledge:index', ['--document' => $document->id])->assertSuccessful();

        Queue::assertPushed(IndexKnowledgeDocumentJob::class);
        $this->assertSame(KnowledgeDocumentStatus::Processing, $document->fresh()->status);
    }

    public function test_index_dry_run_changes_nothing(): void
    {
        Queue::fake();
        $document = KnowledgeDocument::factory()->for($this->base, 'base')->ready()->create();

        $this->artisan('knowledge:index', ['--dry-run' => true])->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(KnowledgeDocumentStatus::Ready, $document->fresh()->status);
    }

    public function test_index_can_target_only_failed_documents(): void
    {
        Queue::fake();
        KnowledgeDocument::factory()->for($this->base, 'base')->approved()->create();
        $failed = KnowledgeDocument::factory()->for($this->base, 'base')->failed()->create();

        $this->artisan('knowledge:index', ['--failed' => true])->assertSuccessful();

        Queue::assertPushed(IndexKnowledgeDocumentJob::class, 1);
        $this->assertSame(KnowledgeDocumentStatus::Processing, $failed->fresh()->status);
    }

    // --- knowledge:sync ------------------------------------------------------

    public function test_sync_corrects_a_wrong_chunk_count(): void
    {
        $document = KnowledgeDocument::factory()->for($this->base, 'base')->approved()->create(['chunk_count' => 99]);
        KnowledgeChunk::factory()->for($document, 'document')->create(['knowledge_base_id' => $this->base->id]);

        $this->artisan('knowledge:sync')->assertSuccessful();

        $this->assertSame(1, $document->fresh()->chunk_count);
    }

    public function test_sync_dry_run_reports_without_correcting(): void
    {
        $document = KnowledgeDocument::factory()->for($this->base, 'base')->approved()->create(['chunk_count' => 99]);
        KnowledgeChunk::factory()->for($document, 'document')->create(['knowledge_base_id' => $this->base->id]);

        $this->artisan('knowledge:sync', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(99, $document->fresh()->chunk_count);
    }

    public function test_sync_never_approves_a_document(): void
    {
        $document = KnowledgeDocument::factory()->for($this->base, 'base')->ready()->create();

        $this->artisan('knowledge:sync')->assertSuccessful();

        $this->assertSame(KnowledgeDocumentStatus::Ready, $document->fresh()->status);
        $this->assertNull($document->fresh()->approved_at);
    }

    // --- knowledge:prune-retrievals -----------------------------------------

    public function test_prune_removes_old_retrievals_and_their_chunks(): void
    {
        $old = KnowledgeRetrieval::factory()->create(['created_at' => now()->subDays(400)]);
        KnowledgeRetrievalChunk::factory()->create(['knowledge_retrieval_id' => $old->id]);
        $recent = KnowledgeRetrieval::factory()->create();

        $this->artisan('knowledge:prune-retrievals')->assertSuccessful();

        $this->assertDatabaseMissing('knowledge_retrievals', ['id' => $old->id]);
        $this->assertDatabaseHas('knowledge_retrievals', ['id' => $recent->id]);
        $this->assertSame(0, KnowledgeRetrievalChunk::where('knowledge_retrieval_id', $old->id)->count());
    }

    /**
     * A explicação de algo que chegou a uma pessoa tem ciclo de vida mais longo
     * que o log de busca. Apagar os dois juntos destruiria a justificativa de uma
     * resposta enviada.
     */
    public function test_prune_never_touches_the_citations_of_a_suggestion(): void
    {
        $citation = ReplySuggestionCitation::factory()->create();
        KnowledgeRetrieval::factory()->create(['created_at' => now()->subDays(400)]);

        $this->artisan('knowledge:prune-retrievals')->assertSuccessful();

        $this->assertDatabaseHas('reply_suggestion_citations', ['id' => $citation->id]);
    }

    public function test_prune_is_disabled_when_the_retention_is_zero(): void
    {
        app(SystemSettingService::class)->updateMany(['knowledge.retrieval_retention_days' => '0']);
        $old = KnowledgeRetrieval::factory()->create(['created_at' => now()->subDays(400)]);

        $this->artisan('knowledge:prune-retrievals')->assertSuccessful();

        $this->assertDatabaseHas('knowledge_retrievals', ['id' => $old->id]);
    }
}
