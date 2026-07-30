<?php

namespace Tests\Feature;

use App\Contracts\KnowledgeRetriever;
use App\Data\Knowledge\RetrievalQuery;
use App\Data\Knowledge\RetrievalResult;
use App\Enums\KnowledgeDocumentStatus;
use App\Enums\RetrievalStrategy;
use App\Models\ConversationFlow;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeRetrieval;
use App\Models\KnowledgeRetrievalChunk;
use App\Services\Knowledge\KnowledgeRetrievalService;
use App\Services\SystemSettingService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Subetapa 9D: recuperação na base oficial.
 *
 * O critério central: aprovação e a condição de existência na busca. Um trecho
 * que não passou por aprovação humana não pode ser alcançado por nenhum caminho.
 */
class KnowledgeRetrievalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
        $this->settings(['knowledge.enabled' => '1', 'knowledge.score_threshold' => '0.10']);
    }

    /** @param array<string, string> $extra */
    private function settings(array $extra): void
    {
        app(SystemSettingService::class)->updateMany($extra);
    }

    private function chunk(KnowledgeDocument $document, string $content, int $index = 0): KnowledgeChunk
    {
        return KnowledgeChunk::factory()
            ->for($document, 'document')
            ->withContent($content)
            ->create(['knowledge_base_id' => $document->knowledge_base_id, 'chunk_index' => $index]);
    }

    private function search(KnowledgeBase $base, string $text, ?RetrievalStrategy $strategy = null): RetrievalResult
    {
        return app(KnowledgeRetriever::class)->retrieve(new RetrievalQuery(
            text: $text,
            baseIds: [$base->id],
            strategy: $strategy ?? RetrievalStrategy::Lexical,
            topK: 5,
            threshold: 0.10,
            maxContextChars: 4000,
        ));
    }

    // =========================================================================
    // Critério: somente conteúdo aprovado aparece na busca
    // =========================================================================

    public function test_approved_document_in_an_active_base_is_retrieved(): void
    {
        $base = KnowledgeBase::factory()->active()->create();
        $document = KnowledgeDocument::factory()->for($base, 'base')->approved()->create();
        $this->chunk($document, 'O gabinete atende de segunda a sexta, das nove as dezessete horas.');

        $result = $this->search($base, 'qual o horário de atendimento do gabinete');

        $this->assertFalse($result->isEmpty());
        $this->assertSame($document->id, $result->chunks[0]->documentId);
    }

    /**
     * Todo status que não seja aprovado precisa ser invisível para a busca, sem
     * exceção: e o único jeito de garantir que rascunho não vira resposta.
     */
    public function test_document_that_is_not_approved_is_never_retrieved(): void
    {
        $base = KnowledgeBase::factory()->active()->create();

        foreach ([
            KnowledgeDocumentStatus::Draft,
            KnowledgeDocumentStatus::Processing,
            KnowledgeDocumentStatus::Ready,
            KnowledgeDocumentStatus::Rejected,
            KnowledgeDocumentStatus::Obsolete,
            KnowledgeDocumentStatus::Failed,
        ] as $status) {
            $document = KnowledgeDocument::factory()->for($base, 'base')->create(['status' => $status]);
            $this->chunk($document, 'O gabinete atende de segunda a sexta, das nove as dezessete horas.');
        }

        $result = $this->search($base, 'qual o horário de atendimento do gabinete');

        $this->assertTrue($result->isEmpty(), 'Nenhum status além de aprovado pode ser recuperado.');
    }

    public function test_approved_document_in_an_inactive_base_is_not_retrieved(): void
    {
        $base = KnowledgeBase::factory()->inactive()->create();
        $document = KnowledgeDocument::factory()->for($base, 'base')->approved()->create();
        $this->chunk($document, 'O gabinete atende de segunda a sexta.');

        $this->assertTrue($this->search($base, 'horário de atendimento do gabinete')->isEmpty());
    }

    public function test_obsolete_document_stops_being_retrieved_without_erasing_history(): void
    {
        $base = KnowledgeBase::factory()->active()->create();
        $document = KnowledgeDocument::factory()->for($base, 'base')->approved()->create();
        $this->chunk($document, 'O gabinete atende de segunda a sexta, das nove as dezessete horas.');

        $before = $this->search($base, 'horário de atendimento do gabinete');
        $this->assertFalse($before->isEmpty());

        $document->update(['status' => KnowledgeDocumentStatus::Obsolete, 'obsoleted_at' => now()]);

        $this->assertTrue($this->search($base, 'horário de atendimento do gabinete')->isEmpty());
        $this->assertDatabaseHas('knowledge_documents', ['id' => $document->id]);
        $this->assertSame(1, KnowledgeChunk::where('knowledge_document_id', $document->id)->count(), 'Obsolescência não apaga trecho.');
    }

    public function test_base_of_another_flow_does_not_leak_into_the_query(): void
    {
        $mine = KnowledgeBase::factory()->active()->create();
        $other = KnowledgeBase::factory()->active()->create();

        $document = KnowledgeDocument::factory()->for($other, 'base')->approved()->create();
        $this->chunk($document, 'O gabinete atende de segunda a sexta, das nove as dezessete horas.');

        $this->assertTrue($this->search($mine, 'horário de atendimento do gabinete')->isEmpty());
    }

    // =========================================================================
    // Limites e degradação
    // =========================================================================

    public function test_query_without_usable_terms_returns_empty_with_a_reason(): void
    {
        $base = KnowledgeBase::factory()->active()->create();

        $result = $this->search($base, 'ok');

        $this->assertTrue($result->isEmpty());
        $this->assertSame('consulta_sem_termos', $result->degradedReason);
    }

    public function test_query_without_an_associated_base_returns_empty_with_a_reason(): void
    {
        $result = app(KnowledgeRetriever::class)->retrieve(new RetrievalQuery(
            text: 'horário de atendimento',
            baseIds: [],
            strategy: RetrievalStrategy::Lexical,
            topK: 5,
            threshold: 0.1,
            maxContextChars: 4000,
        ));

        $this->assertTrue($result->isEmpty());
        $this->assertSame('sem_base_associada', $result->degradedReason);
    }

    public function test_top_k_caps_the_number_of_returned_chunks(): void
    {
        $base = KnowledgeBase::factory()->active()->create();
        $document = KnowledgeDocument::factory()->for($base, 'base')->approved()->create();

        for ($i = 0; $i < 8; $i++) {
            $this->chunk($document, "Trecho {$i} sobre atendimento no gabinete da região.", $i);
        }

        $result = app(KnowledgeRetriever::class)->retrieve(new RetrievalQuery(
            text: 'atendimento no gabinete',
            baseIds: [$base->id],
            strategy: RetrievalStrategy::Lexical,
            topK: 3,
            threshold: 0.01,
            maxContextChars: 100000,
        ));

        $this->assertCount(3, $result->chunks);
    }

    public function test_context_budget_truncates_instead_of_returning_nothing(): void
    {
        $base = KnowledgeBase::factory()->active()->create();
        $document = KnowledgeDocument::factory()->for($base, 'base')->approved()->create();
        $this->chunk($document, str_repeat('atendimento no gabinete da região serrana. ', 200));

        $result = app(KnowledgeRetriever::class)->retrieve(new RetrievalQuery(
            text: 'atendimento no gabinete',
            baseIds: [$base->id],
            strategy: RetrievalStrategy::Lexical,
            topK: 5,
            threshold: 0.01,
            maxContextChars: 300,
        ));

        $this->assertFalse($result->isEmpty(), 'Um trecho grande demais e cortado, não descartado.');
        $this->assertLessThanOrEqual(300, mb_strlen($result->chunks[0]->content));
    }

    public function test_vector_search_refuses_above_the_candidate_limit_and_reports_it(): void
    {
        $this->settings(['knowledge.max_vector_candidates' => '2']);

        // Provedor apenas configurado, nunca chamado: a recusa por limite acontece
        // antes de qualquer requisição, e e isso que o teste precisa provar.
        Config::set('knowledge.embeddings.provider', 'openai');
        Config::set('knowledge.embeddings.openai.key', 'chave-de-teste');
        Http::fake(['*' => Http::response([], 500)]);

        $base = KnowledgeBase::factory()->active()->create();
        $document = KnowledgeDocument::factory()->for($base, 'base')->approved()->create();

        for ($i = 0; $i < 3; $i++) {
            KnowledgeChunk::factory()
                ->for($document, 'document')
                ->withContent("Trecho {$i} sobre atendimento no gabinete.")
                ->withEmbedding([0.1, 0.2, 0.3])
                ->create(['knowledge_base_id' => $base->id, 'chunk_index' => $i]);
        }

        $result = $this->search($base, 'atendimento no gabinete', RetrievalStrategy::Vector);

        $this->assertSame('limite_de_candidatos_excedido', $result->degradedReason);
        Http::assertNothingSent();
    }

    public function test_hybrid_falls_back_to_lexical_when_no_chunk_has_an_embedding(): void
    {
        $base = KnowledgeBase::factory()->active()->create();
        $document = KnowledgeDocument::factory()->for($base, 'base')->approved()->create();
        $this->chunk($document, 'O gabinete atende de segunda a sexta, das nove as dezessete horas.');

        $result = $this->search($base, 'horário de atendimento do gabinete', RetrievalStrategy::Hybrid);

        $this->assertFalse($result->isEmpty(), 'Sem vetor a busca híbrida ainda entrega o resultado léxico.');
    }

    // =========================================================================
    // Rastreabilidade
    // =========================================================================

    public function test_retrieval_is_logged_with_a_content_snapshot_of_every_returned_chunk(): void
    {
        $base = KnowledgeBase::factory()->active()->create();
        $document = KnowledgeDocument::factory()->for($base, 'base')->approved()->create();
        $chunk = $this->chunk($document, 'O gabinete atende de segunda a sexta, das nove as dezessete horas.');

        $flow = ConversationFlow::factory()->create();
        $flow->knowledgeBases()->attach($base->id, ['priority' => 0]);

        $outcome = app(KnowledgeRetrievalService::class)->retrieveForFlow($flow, 'horário de atendimento do gabinete');

        $this->assertNotNull($outcome['retrieval']);
        $this->assertSame(1, $outcome['retrieval']->returned_count);

        $logged = KnowledgeRetrievalChunk::where('knowledge_retrieval_id', $outcome['retrieval']->id)->firstOrFail();
        $this->assertSame($chunk->content, $logged->content_snapshot);
        $this->assertSame($document->title, $logged->document_title_snapshot);
    }

    public function test_snapshot_survives_the_deletion_of_the_chunk(): void
    {
        $base = KnowledgeBase::factory()->active()->create();
        $document = KnowledgeDocument::factory()->for($base, 'base')->approved()->create();
        $chunk = $this->chunk($document, 'O gabinete atende de segunda a sexta, das nove as dezessete horas.');

        $flow = ConversationFlow::factory()->create();
        $flow->knowledgeBases()->attach($base->id, ['priority' => 0]);

        $outcome = app(KnowledgeRetrievalService::class)->retrieveForFlow($flow, 'horário de atendimento do gabinete');
        $chunk->delete();

        $logged = KnowledgeRetrievalChunk::where('knowledge_retrieval_id', $outcome['retrieval']->id)->firstOrFail();

        $this->assertNull($logged->knowledge_chunk_id, 'O vínculo cai...');
        $this->assertStringContainsString('gabinete atende', (string) $logged->content_snapshot, '...mas a explicacao continua existindo.');
    }

    public function test_a_flow_without_an_associated_base_produces_no_retrieval_record(): void
    {
        $flow = ConversationFlow::factory()->create();

        $outcome = app(KnowledgeRetrievalService::class)->retrieveForFlow($flow, 'horário de atendimento');

        $this->assertNull($outcome['retrieval'], 'Sem base associada nenhuma busca aconteceu, então não ha o que registrar.');
        $this->assertSame(0, KnowledgeRetrieval::count());
    }

    public function test_retrieval_is_disabled_when_the_global_switch_is_off(): void
    {
        $this->settings(['knowledge.enabled' => '0']);

        $base = KnowledgeBase::factory()->active()->create();
        $document = KnowledgeDocument::factory()->for($base, 'base')->approved()->create();
        $this->chunk($document, 'O gabinete atende de segunda a sexta.');

        $flow = ConversationFlow::factory()->create();
        $flow->knowledgeBases()->attach($base->id, ['priority' => 0]);

        $outcome = app(KnowledgeRetrievalService::class)->retrieveForFlow($flow, 'horário de atendimento do gabinete');

        $this->assertTrue($outcome['result']->isEmpty());
        $this->assertNull($outcome['retrieval']);
    }

    // =========================================================================
    // Isolamento estrutural
    // =========================================================================

    /**
     * A proibição de usar conversa de terceiro ou opinião da população como fonte
     * precisa ser estrutural. Este teste le o próprio arquivo do recuperador: se
     * alguém acrescentar uma consulta a conversa, o teste quebra antes da revisão.
     */
    public function test_the_retriever_never_references_conversation_or_contact_data(): void
    {
        $source = $this->codeWithoutComments(app_path('Services/Knowledge/LocalKnowledgeRetriever.php'));

        foreach ([
            'Conversation',
            'Contact',
            'ConversationInsight',
            'ConversationMessage',
            'conversations',
            'contacts',
            'conversation_messages',
            'conversation_insights',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                "O recuperador não pode conhecer {$forbidden}: a opinião da população nunca e fonte de resposta individual."
            );
        }
    }

    public function test_the_retrieval_query_object_carries_no_contact_identifier(): void
    {
        $source = $this->codeWithoutComments(app_path('Data/Knowledge/RetrievalQuery.php'));

        foreach (['contact', 'Contact', 'phone', 'conversation_id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    /**
     * Código do arquivo sem comentários.
     *
     * O comentário que explica a proibição precisa poder citar o que esta
     * proibido. E o código que este teste inspeciona, não a prosa.
     */
    private function codeWithoutComments(string $path): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
