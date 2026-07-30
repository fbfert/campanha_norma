<?php

namespace Tests\Feature;

use App\Contracts\KnowledgeRetriever;
use App\Data\Knowledge\RetrievalQuery;
use App\Enums\RetrievalStrategy;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\SystemSettingService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Subetapa 9D: limites do armazenamento vetorial.
 *
 * O ADR 0001 escolheu guardar vetores em coluna `blob` de um banco relacional em
 * vez de introduzir um banco vetorial. Estes testes são a contrapartida dessa
 * escolha: eles falham se o comportamento sair da faixa documentada la.
 */
class KnowledgeVectorLimitsTest extends TestCase
{
    use RefreshDatabase;

    /** Bytes por float de 32 bits. */
    private const BYTES_PER_FLOAT = 4;

    /** Capacidade de uma coluna `blob` em MariaDB. */
    private const BLOB_CAPACITY = 65535;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
    }

    /** @param array<string, string> $extra */
    private function settings(array $extra): void
    {
        app(SystemSettingService::class)->updateMany($extra);
    }

    /** @return array<int, float> */
    private function vector(int $dimensions, float $seed = 0.5): array
    {
        $vector = [];

        for ($i = 0; $i < $dimensions; $i++) {
            $vector[] = round(sin(($i + 1) * $seed), 6);
        }

        return $vector;
    }

    // =========================================================================
    // Serialização
    // =========================================================================

    public function test_a_vector_survives_the_round_trip_within_float32_precision(): void
    {
        $original = $this->vector(1536);

        $recovered = KnowledgeChunk::unpackEmbedding(KnowledgeChunk::packEmbedding($original));

        $this->assertCount(1536, $recovered);

        foreach ($original as $position => $value) {
            $this->assertEqualsWithDelta($value, $recovered[$position], 1e-6, "Divergência na posição {$position}.");
        }
    }

    public function test_the_serialized_size_is_four_bytes_per_dimension(): void
    {
        $packed = KnowledgeChunk::packEmbedding($this->vector(1536));

        $this->assertSame(1536 * self::BYTES_PER_FLOAT, strlen($packed));
    }

    /**
     * O número que sustenta a decisão do ADR: 6.144 bytes por trecho.
     */
    public function test_the_documented_corpus_estimate_still_holds(): void
    {
        $bytesPerChunk = 1536 * self::BYTES_PER_FLOAT;
        $megabytesForTenThousandChunks = (10000 * $bytesPerChunk) / 1024 / 1024;

        $this->assertSame(6144, $bytesPerChunk);
        $this->assertLessThan(60, $megabytesForTenThousandChunks, 'A estimativa de ~59 MB do ADR 0001 mudou.');
    }

    /**
     * Teto da coluna. Se um modelo futuro passar disso, o ADR precisa ser
     * revisitado antes de o vetor ser truncado em silêncio pelo banco.
     */
    public function test_the_column_ceiling_is_above_every_model_in_use(): void
    {
        $maximumDimensions = intdiv(self::BLOB_CAPACITY, self::BYTES_PER_FLOAT);

        $this->assertSame(16383, $maximumDimensions);
        $this->assertGreaterThan(3072, $maximumDimensions, 'O maior modelo comercial em uso hoje usa 3.072 dimensões.');
        $this->assertLessThanOrEqual(self::BLOB_CAPACITY, 3072 * self::BYTES_PER_FLOAT);
    }

    public function test_a_large_vector_survives_persistence_and_reading(): void
    {
        $base = KnowledgeBase::factory()->active()->create();
        $document = KnowledgeDocument::factory()->for($base, 'base')->approved()->create();

        $vector = $this->vector(3072);

        $chunk = KnowledgeChunk::factory()
            ->for($document, 'document')
            ->withContent('Trecho com vetor grande.')
            ->withEmbedding($vector)
            ->create(['knowledge_base_id' => $base->id]);

        $recovered = $chunk->fresh()->vector();

        $this->assertCount(3072, $recovered);
        $this->assertEqualsWithDelta($vector[0], $recovered[0], 1e-6);
        $this->assertEqualsWithDelta($vector[3071], $recovered[3071], 1e-6);
    }

    // =========================================================================
    // Teto de candidatos
    // =========================================================================

    public function test_the_candidate_limit_refuses_instead_of_degrading_in_silence(): void
    {
        $this->settings(['knowledge.max_vector_candidates' => '3']);
        Config::set('knowledge.embeddings.provider', 'openai');
        Config::set('knowledge.embeddings.openai.key', 'chave-de-teste');
        Http::fake(['*' => Http::response([], 500)]);

        $base = KnowledgeBase::factory()->active()->create();
        $document = KnowledgeDocument::factory()->for($base, 'base')->approved()->create();

        for ($i = 0; $i < 4; $i++) {
            KnowledgeChunk::factory()
                ->for($document, 'document')
                ->withContent("Trecho {$i} sobre atendimento no gabinete.")
                ->withEmbedding($this->vector(8))
                ->create(['knowledge_base_id' => $base->id, 'chunk_index' => $i]);
        }

        $result = app(KnowledgeRetriever::class)->retrieve(new RetrievalQuery(
            text: 'atendimento no gabinete',
            baseIds: [$base->id],
            strategy: RetrievalStrategy::Vector,
            topK: 5,
            threshold: 0.1,
            maxContextChars: 4000,
        ));

        $this->assertSame('limite_de_candidatos_excedido', $result->degradedReason);
        $this->assertTrue($result->isEmpty());
        Http::assertNothingSent();
    }

    public function test_the_hybrid_strategy_still_answers_when_the_vector_side_refuses(): void
    {
        $this->settings(['knowledge.max_vector_candidates' => '1']);
        Config::set('knowledge.embeddings.provider', 'openai');
        Config::set('knowledge.embeddings.openai.key', 'chave-de-teste');
        Http::fake(['*' => Http::response([], 500)]);

        $base = KnowledgeBase::factory()->active()->create();
        $document = KnowledgeDocument::factory()->for($base, 'base')->approved()->create();

        for ($i = 0; $i < 3; $i++) {
            KnowledgeChunk::factory()
                ->for($document, 'document')
                ->withContent("Trecho {$i}: o gabinete atende de segunda a sexta, das nove as dezessete horas.")
                ->withEmbedding($this->vector(8))
                ->create(['knowledge_base_id' => $base->id, 'chunk_index' => $i]);
        }

        $result = app(KnowledgeRetriever::class)->retrieve(new RetrievalQuery(
            text: 'horário de atendimento do gabinete',
            baseIds: [$base->id],
            strategy: RetrievalStrategy::Hybrid,
            topK: 5,
            threshold: 0.1,
            maxContextChars: 4000,
        ));

        $this->assertSame('limite_de_candidatos_excedido', $result->degradedReason);
        $this->assertFalse($result->isEmpty(), 'A recusa vetorial não pode zerar a busca: a léxica responde.');
    }

    // =========================================================================
    // Troca de modelo
    // =========================================================================

    public function test_vectors_of_a_different_dimension_are_ignored_not_guessed(): void
    {
        Config::set('knowledge.embeddings.provider', 'openai');
        Config::set('knowledge.embeddings.openai.key', 'chave-de-teste');
        Config::set('knowledge.embeddings.openai.dimensions', 4);

        $base = KnowledgeBase::factory()->active()->create();
        $document = KnowledgeDocument::factory()->for($base, 'base')->approved()->create();

        // Trecho gravado por um modelo antigo, com outra dimensão.
        KnowledgeChunk::factory()
            ->for($document, 'document')
            ->withContent('Trecho do modelo antigo sobre atendimento.')
            ->withEmbedding($this->vector(8))
            ->create(['knowledge_base_id' => $base->id, 'chunk_index' => 0]);

        Http::fake(['*' => Http::response([
            'data' => [['index' => 0, 'embedding' => [0.1, 0.2, 0.3, 0.4]]],
        ])]);

        $result = app(KnowledgeRetriever::class)->retrieve(new RetrievalQuery(
            text: 'atendimento',
            baseIds: [$base->id],
            strategy: RetrievalStrategy::Vector,
            topK: 5,
            threshold: 0.1,
            maxContextChars: 4000,
        ));

        $this->assertTrue($result->isEmpty(), 'Comparar vetores de modelos diferentes produziria número sem sentido.');
    }
}
