<?php

namespace Database\Factories;

use App\Enums\ConversationFlowStage;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConversationFlowState> */
class ConversationFlowStateFactory extends Factory
{
    protected $model = ConversationFlowState::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'conversation_flow_id' => ConversationFlow::factory(),
            'current_stage' => ConversationFlowStage::WaitingPermission,
            'automated_messages_count' => 1,
            'attempts_count' => 0,
            'is_paused' => false,
            'needs_human_review' => false,
            'started_at' => now(),
            'last_transition_at' => now(),
            'expires_at' => now()->addHours(48),
        ];
    }

    public function paused(): self
    {
        return $this->state(fn (): array => ['is_paused' => true]);
    }
}
