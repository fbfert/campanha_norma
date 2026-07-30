<?php

namespace Tests\Unit;

use App\Contracts\AnswerGroundingValidator;
use App\Data\Knowledge\RetrievalResult;
use App\Data\Knowledge\RetrievedChunk;
use App\Enums\GroundingStatus;
use App\Enums\RetrievalStrategy;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Services\Knowledge\GroundingValidator;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Subetapa 9D: validação de fundamentação.
 *
 * Esta classe existe para o caso em que o modelo afirma estar fundamentado e não
 * esta. Cada teste aqui e um jeito diferente de mentir sobre evidência.
 */
class GroundingValidatorTest extends TestCase
{
    use RefreshDatabase;

    private KnowledgeBase $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
        $this->base = KnowledgeBase::factory()->active()->create();
    }

    private function validator(): AnswerGroundingValidator
    {
        return app(AnswerGroundingValidator::class);
    }

    private function document(string $status = 'approved'): KnowledgeDocument
    {
        $factory = KnowledgeDocument::factory()->for($this->base, 'base');

        return match ($status) {
            'approved' => $factory->approved()->create(),
            'obsolete' => $factory->obsolete()->create(),
            default => $factory->create(),
        };
    }

    private function retrieval(KnowledgeDocument $document, string $content, string $reference = 'c1'): RetrievalResult
    {
        return new RetrievalResult(
            [new RetrievedChunk(
                chunkId: 1,
                documentId: $document->id,
                baseId: $this->base->id,
                documentTitle: $document->title,
                documentVersion: 1,
                content: $content,
                score: 0.9,
                externalChunkId: $reference,
            )],
            RetrievalStrategy::Lexical,
            candidateCount: 1,
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function cite(int $documentId, string $reference = 'c1'): array
    {
        return [['document_id' => $documentId, 'chunk_id' => $reference, 'page' => null, 'section' => null]];
    }

    // --- Aprovação -----------------------------------------------------------

    public function test_factual_answer_supported_by_the_cited_excerpt_is_grounded(): void
    {
        $document = $this->document();
        $retrieval = $this->retrieval($document, 'O gabinete atende de segunda a sexta, das 9h as 17h, na Rua Central 1500.');

        $verdict = $this->validator()->validate(
            'O atendimento acontece de segunda a sexta, das 9h as 17h.',
            $this->cite($document->id),
            $retrieval,
            true,
        );

        $this->assertSame(GroundingStatus::Grounded, $verdict->status);
        $this->assertTrue($verdict->allowsSending());
        $this->assertCount(1, $verdict->citations);
    }

    public function test_a_question_without_factual_claim_does_not_require_evidence(): void
    {
        $document = $this->document();

        $verdict = $this->validator()->validate(
            'Obrigada por explicar. O que mais pesa hoje no seu dia a dia?',
            [],
            $this->retrieval($document, 'Conteúdo qualquer.'),
            false,
        );

        $this->assertSame(GroundingStatus::NotRequired, $verdict->status);
        $this->assertTrue($verdict->allowsSending());
    }

    public function test_number_written_in_a_different_format_still_counts_as_supported(): void
    {
        $document = $this->document();
        $retrieval = $this->retrieval($document, 'A proposta preve 1.500 novas vagas na rede estadual.');

        $verdict = $this->validator()->validate(
            'A proposta preve 1500 novas vagas.',
            $this->cite($document->id),
            $retrieval,
            true,
        );

        $this->assertSame(GroundingStatus::Grounded, $verdict->status, 'Reprovar por formatação produziria handoff onde havia evidência.');
    }

    // --- Reprovação ----------------------------------------------------------

    public function test_factual_answer_without_any_citation_is_refused(): void
    {
        $document = $this->document();

        $verdict = $this->validator()->validate(
            'A professora norma apresentou o projeto de lei em 2024.',
            [],
            $this->retrieval($document, 'Conteúdo sem relação.'),
            false,
        );

        $this->assertSame(GroundingStatus::NoEvidence, $verdict->status);
        $this->assertFalse($verdict->allowsSending());
    }

    public function test_citation_outside_the_retrieved_set_is_refused(): void
    {
        $document = $this->document();

        $verdict = $this->validator()->validate(
            'O atendimento acontece as segundas.',
            $this->cite($document->id, 'trecho-inventado-999'),
            $this->retrieval($document, 'Texto oficial.', 'c1'),
            true,
        );

        // A referência não existe, mas o documento esta no conjunto: o modelo
        // acertou a fonte e errou o identificador interno, e isso e aceito.
        $this->assertSame(GroundingStatus::Grounded, $verdict->status);
    }

    public function test_citation_of_a_document_that_was_never_retrieved_is_refused(): void
    {
        $document = $this->document();

        $verdict = $this->validator()->validate(
            'O atendimento acontece as segundas.',
            $this->cite(999999, 'c-inexistente'),
            $this->retrieval($document, 'Texto oficial.'),
            true,
        );

        $this->assertSame(GroundingStatus::InvalidCitation, $verdict->status);
        $this->assertFalse($verdict->allowsSending());
    }

    public function test_citation_of_a_non_retrievable_document_is_refused(): void
    {
        $obsolete = $this->document('obsolete');

        $verdict = $this->validator()->validate(
            'O atendimento acontece as segundas.',
            $this->cite($obsolete->id),
            $this->retrieval($obsolete, 'Texto que já foi oficial.'),
            true,
        );

        $this->assertSame(GroundingStatus::ObsoleteCitation, $verdict->status);
        $this->assertFalse($verdict->allowsSending());
    }

    public function test_claiming_grounded_without_citing_anything_is_refused(): void
    {
        $document = $this->document();

        $verdict = $this->validator()->validate(
            'Vou te explicar como funciona.',
            [],
            $this->retrieval($document, 'Texto oficial.'),
            true,
        );

        $this->assertSame(GroundingStatus::GroundedWithoutCitation, $verdict->status);
        $this->assertFalse($verdict->allowsSending());
    }

    public function test_number_absent_from_the_cited_excerpt_is_refused(): void
    {
        $document = $this->document();
        $retrieval = $this->retrieval($document, 'A proposta trata da ampliação da rede estadual de ensino.');

        $verdict = $this->validator()->validate(
            'A proposta preve 4200 novas vagas.',
            $this->cite($document->id),
            $retrieval,
            true,
        );

        $this->assertSame(GroundingStatus::UnsupportedNumber, $verdict->status);
        $this->assertStringContainsString('4200', (string) $verdict->errorSummary());
    }

    public function test_date_absent_from_the_cited_excerpt_is_refused(): void
    {
        $document = $this->document();
        $retrieval = $this->retrieval($document, 'A proposta trata da ampliação da rede estadual de ensino.');

        $verdict = $this->validator()->validate(
            'A proposta foi apresentada em 12/03/2025.',
            $this->cite($document->id),
            $retrieval,
            true,
        );

        $this->assertSame(GroundingStatus::UnsupportedDate, $verdict->status);
    }

    public function test_commitment_without_explicit_support_is_refused(): void
    {
        $document = $this->document();
        $retrieval = $this->retrieval($document, 'A professora norma atua na comissão de educação da casa.');

        $verdict = $this->validator()->validate(
            'A professora norma vai construir uma escola no seu bairro.',
            $this->cite($document->id),
            $retrieval,
            true,
        );

        $this->assertSame(GroundingStatus::UnsupportedCommitment, $verdict->status);
        $this->assertFalse($verdict->allowsSending());
    }

    public function test_two_excerpts_cannot_be_merged_into_a_number_neither_states(): void
    {
        $document = $this->document();

        $retrieval = new RetrievalResult(
            [
                new RetrievedChunk(1, $document->id, $this->base->id, $document->title, 1, 'A rede tem 30 escolas na região sul.', 0.9, externalChunkId: 'c1'),
                new RetrievedChunk(2, $document->id, $this->base->id, $document->title, 1, 'A rede tem 45 escolas na região norte.', 0.8, externalChunkId: 'c2'),
            ],
            RetrievalStrategy::Lexical,
            candidateCount: 2,
        );

        $verdict = $this->validator()->validate(
            'A rede tem 75 escolas nas duas regiões.',
            [
                ['document_id' => $document->id, 'chunk_id' => 'c1', 'page' => null, 'section' => null],
                ['document_id' => $document->id, 'chunk_id' => 'c2', 'page' => null, 'section' => null],
            ],
            $retrieval,
            true,
        );

        $this->assertSame(GroundingStatus::UnsupportedNumber, $verdict->status, 'Somar dois trechos não produz evidência.');
    }

    public function test_page_and_section_come_from_the_retrieved_chunk_not_from_the_model(): void
    {
        $document = $this->document();

        $retrieval = new RetrievalResult(
            [new RetrievedChunk(1, $document->id, $this->base->id, $document->title, 1, 'O gabinete atende as segundas.', 0.9, page: 7, section: 'Atendimento', externalChunkId: 'c1')],
            RetrievalStrategy::Lexical,
            candidateCount: 1,
        );

        $verdict = $this->validator()->validate(
            'O atendimento acontece as segundas.',
            [['document_id' => $document->id, 'chunk_id' => 'c1', 'page' => 999, 'section' => 'Seção inventada']],
            $retrieval,
            true,
        );

        $this->assertSame(GroundingStatus::Grounded, $verdict->status);
        $this->assertSame(7, $verdict->citations[0]['page']);
        $this->assertSame('Atendimento', $verdict->citations[0]['section']);
    }

    public function test_empty_text_never_requires_evidence(): void
    {
        $document = $this->document();

        $verdict = $this->validator()->validate(null, [], $this->retrieval($document, 'Texto.'), false);

        $this->assertSame(GroundingStatus::NotRequired, $verdict->status);
    }

    public function test_repeated_citations_of_the_same_chunk_are_recorded_once(): void
    {
        $document = $this->document();

        $verdict = $this->validator()->validate(
            'O atendimento acontece as segundas.',
            array_merge($this->cite($document->id), $this->cite($document->id)),
            $this->retrieval($document, 'O gabinete atende as segundas.'),
            true,
        );

        $this->assertCount(1, $verdict->citations);
    }

    public function test_chunks_of_returns_the_retrieved_chunks_of_valid_citations(): void
    {
        $document = $this->document();

        $verdict = $this->validator()->validate(
            'O atendimento acontece as segundas.',
            $this->cite($document->id),
            $this->retrieval($document, 'O gabinete atende as segundas.'),
            true,
        );

        $chunks = GroundingValidator::chunksOf($verdict->citations);

        $this->assertCount(1, $chunks);
        $this->assertInstanceOf(RetrievedChunk::class, $chunks[0]);
    }
}
