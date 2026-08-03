<?php

namespace Database\Factories;

use App\Enums\ClassificationSource;
use App\Enums\MessageClassification;
use App\Models\ConversationMessageClassification;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConversationMessageClassification> */
class ConversationMessageClassificationFactory extends Factory
{
    protected $model = ConversationMessageClassification::class;

    public function definition(): array
    {
        return [
            'purpose' => 'classify_message',
            'classification' => MessageClassification::QuestionAnswer,
            'source' => ClassificationSource::Ai,
            'confidence' => 0.95,
            'requires_human_review' => false,
            'prompt_version' => 'v1',
            'schema_version' => 1,
        ];
    }
}
