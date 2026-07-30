<?php

namespace Database\Factories;

use App\Enums\InsightUrgency;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationInsight;
use App\Models\ConversationMessage;
use App\Models\InsightTopic;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConversationInsight> */
class ConversationInsightFactory extends Factory
{
    protected $model = ConversationInsight::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'contact_id' => Contact::factory(),
            'source_message_id' => ConversationMessage::factory(),
            'insight_topic_id' => InsightTopic::factory(),
            'main_topic_raw' => 'saude',
            'summary' => 'A pessoa relatou demora no atendimento do posto de saude.',
            'identified_problem' => 'Demora no atendimento',
            'suggested_action' => 'Ampliar equipe do posto',
            'desired_result' => 'Atendimento mais rapido',
            'urgency' => InsightUrgency::Medium->value,
            'confidence' => 0.85,
            'requires_human_review' => false,
            'reviewed' => false,
            'extraction_version' => 1,
            'prompt_version' => 'v1',
        ];
    }

    public function reviewed(?string $reason = null): static
    {
        return $this->state(fn (): array => [
            'reviewed' => true,
            'reviewed_at' => now(),
            'review_reason' => $reason,
        ]);
    }

    public function lowConfidence(): static
    {
        return $this->state(fn (): array => [
            'confidence' => 0.30,
            'requires_human_review' => true,
        ]);
    }

    public function withLocality(string $locality, ?string $region = null): static
    {
        return $this->state(fn (): array => [
            'locality_text' => $locality,
            'locality_normalized' => $locality,
            'region' => $region,
        ]);
    }
}
