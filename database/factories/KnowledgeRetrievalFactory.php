<?php

namespace Database\Factories;

use App\Enums\RetrievalStrategy;
use App\Models\KnowledgeRetrieval;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeRetrieval>
 */
class KnowledgeRetrievalFactory extends Factory
{
    protected $model = KnowledgeRetrieval::class;

    public function definition(): array
    {
        return [
            'query_text' => 'horario de atendimento do gabinete',
            'strategy' => RetrievalStrategy::Lexical,
            'top_k' => 5,
            'threshold' => 0.25,
            'candidate_count' => 1,
            'returned_count' => 1,
            'max_score' => 0.9,
            'min_score' => 0.9,
            'duration_ms' => 5,
            'provider' => 'local',
            'is_test' => false,
        ];
    }

    public function test(): static
    {
        return $this->state(fn (): array => ['is_test' => true]);
    }

    public function empty(string $reason = 'consulta_sem_termos'): static
    {
        return $this->state(fn (): array => [
            'candidate_count' => 0,
            'returned_count' => 0,
            'max_score' => null,
            'min_score' => null,
            'degraded_reason' => $reason,
        ]);
    }
}
