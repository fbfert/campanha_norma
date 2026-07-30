<?php

namespace Database\Factories;

use App\Enums\ReplySuggestionAction;
use App\Enums\ReplySuggestionStatus;
use App\Enums\ResponseGenerationMode;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationReplySuggestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConversationReplySuggestion> */
class ConversationReplySuggestionFactory extends Factory
{
    protected $model = ConversationReplySuggestion::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'source_message_id' => ConversationMessage::factory(),
            'status' => ReplySuggestionStatus::Pending,
            'action' => ReplySuggestionAction::SuggestReply,
            'generated_text' => 'Obrigada por explicar. O maior problema hoje e a falta de profissionais ou a distancia ate o atendimento?',
            'confidence' => 0.92,
            'requires_human_review' => false,
            'mode' => ResponseGenerationMode::ApprovalRequired,
            'prompt_version' => 'v1',
            'schema_version' => 1,
            'turn_number' => 1,
            'generation_attempt' => 1,
        ];
    }

    public function configure(): static
    {
        // A coluna espelho e o que garante unicidade da sugestao viva.
        return $this->afterMaking(function (ConversationReplySuggestion $suggestion): void {
            if ($suggestion->status->isLive() && $suggestion->active_source_message_id === null) {
                $suggestion->active_source_message_id = $suggestion->source_message_id;
            }
        });
    }
}
