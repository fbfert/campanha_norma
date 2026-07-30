<?php

namespace Database\Factories;

use App\Enums\KnowledgeDocumentStatus;
use App\Enums\KnowledgeDocumentType;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeDocument>
 */
class KnowledgeDocumentFactory extends Factory
{
    protected $model = KnowledgeDocument::class;

    public function definition(): array
    {
        return [
            'knowledge_base_id' => KnowledgeBase::factory(),
            'title' => 'Documento '.$this->faker->unique()->numberBetween(1, 100000),
            'type' => KnowledgeDocumentType::InstitutionalCompetence,
            'source' => 'Equipe responsavel',
            'disk' => 'local',
            'file_path' => 'knowledge-documents/'.$this->faker->uuid().'.txt',
            'original_filename' => 'documento.txt',
            'mime_type' => 'text/plain',
            'file_size' => 1024,
            'content_hash' => hash('sha256', (string) $this->faker->unique()->numberBetween(1, 1000000)),
            // Padrao rascunho: nenhum documento nasce recuperavel.
            'status' => KnowledgeDocumentStatus::Draft,
            'version' => 1,
            'chunk_count' => 0,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn (): array => [
            'status' => KnowledgeDocumentStatus::Ready,
            'indexed_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => KnowledgeDocumentStatus::Approved,
            'indexed_at' => now(),
            'approved_at' => now(),
        ]);
    }

    public function obsolete(): static
    {
        return $this->state(fn (): array => [
            'status' => KnowledgeDocumentStatus::Obsolete,
            'obsoleted_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => KnowledgeDocumentStatus::Failed,
            'error_message' => 'extracao_vazia',
        ]);
    }
}
