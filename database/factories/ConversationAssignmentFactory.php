<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConversationAssignment> */
class ConversationAssignmentFactory extends Factory
{
    protected $model = ConversationAssignment::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'assigned_user_id' => User::factory(),
            'assigned_by' => User::factory(),
            'assigned_at' => now(),
        ];
    }
}
