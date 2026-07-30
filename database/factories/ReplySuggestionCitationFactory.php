<?php

namespace Database\Factories;

use App\Models\ConversationReplySuggestion;
use App\Models\ReplySuggestionCitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReplySuggestionCitation>
 */
class ReplySuggestionCitationFactory extends Factory
{
    protected $model = ReplySuggestionCitation::class;

    public function definition(): array
    {
        return [
            'conversation_reply_suggestion_id' => ConversationReplySuggestion::factory(),
            'document_title_snapshot' => 'Canais de atendimento do gabinete',
            'document_version' => 1,
            'chunk_reference' => (string) $this->faker->unique()->numberBetween(1, 100000),
            'content_snapshot' => 'O gabinete atende de segunda a sexta, das nove as dezessete horas.',
            'score' => 0.9,
            'is_valid' => true,
        ];
    }

    /**
     * Citação recusada: sem vínculo de documento, porque o identificador que o
     * modelo inventou não aponta para nada.
     */
    public function invalid(string $reason = 'fora do conjunto recuperado'): static
    {
        return $this->state(fn (): array => [
            'knowledge_document_id' => null,
            'knowledge_retrieval_chunk_id' => null,
            'document_title_snapshot' => null,
            'document_version' => null,
            'content_snapshot' => null,
            'score' => null,
            'is_valid' => false,
            'invalid_reason' => $reason,
        ]);
    }
}
