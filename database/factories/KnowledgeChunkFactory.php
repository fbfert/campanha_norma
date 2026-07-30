<?php

namespace Database\Factories;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Knowledge\TextNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeChunk>
 */
class KnowledgeChunkFactory extends Factory
{
    protected $model = KnowledgeChunk::class;

    public function definition(): array
    {
        $content = $this->faker->paragraph();

        return [
            'knowledge_document_id' => KnowledgeDocument::factory(),
            'knowledge_base_id' => fn (array $attributes): int => (int) KnowledgeDocument::query()
                ->whereKey($attributes['knowledge_document_id'])
                ->value('knowledge_base_id'),
            'chunk_index' => 0,
            'content' => $content,
            // A coluna normalizada e populada pelo mesmo normalizador da indexação:
            // fixture com normalização própria testaria uma busca que não existe.
            'search_text' => app(TextNormalizer::class)->normalize($content),
            'content_hash' => hash('sha256', $content),
            'token_estimate' => (int) ceil(mb_strlen($content) / 4),
        ];
    }

    public function withContent(string $content): static
    {
        return $this->state(fn (): array => [
            'content' => $content,
            'search_text' => app(TextNormalizer::class)->normalize($content),
            'content_hash' => hash('sha256', $content),
            'token_estimate' => (int) ceil(mb_strlen($content) / 4),
        ]);
    }

    /**
     * @param  array<int, float>  $vector
     */
    public function withEmbedding(array $vector, string $model = 'fake-embedding'): static
    {
        return $this->state(fn (): array => [
            'embedding' => KnowledgeChunk::packEmbedding($vector),
            'embedding_provider' => 'fake',
            'embedding_model' => $model,
            'embedding_dimensions' => count($vector),
            'embedded_at' => now(),
        ]);
    }
}
