<?php

namespace Database\Factories;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeRetrieval;
use App\Models\KnowledgeRetrievalChunk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeRetrievalChunk>
 */
class KnowledgeRetrievalChunkFactory extends Factory
{
    protected $model = KnowledgeRetrievalChunk::class;

    public function definition(): array
    {
        $content = 'O gabinete atende de segunda a sexta, das nove as dezessete horas.';

        return [
            'knowledge_retrieval_id' => KnowledgeRetrieval::factory(),
            'knowledge_chunk_id' => KnowledgeChunk::factory(),
            'knowledge_document_id' => fn (array $attributes): ?int => KnowledgeChunk::query()
                ->whereKey($attributes['knowledge_chunk_id'])
                ->value('knowledge_document_id'),
            'document_title_snapshot' => 'Canais de atendimento do gabinete',
            'document_version' => 1,
            'chunk_reference' => (string) $this->faker->unique()->numberBetween(1, 100000),
            // O snapshot e o ponto do model: ele precisa sobreviver a exclusão do
            // trecho, então a fixture o preenche sempre.
            'content_snapshot' => $content,
            'score' => 0.9,
            'position' => 0,
        ];
    }
}
