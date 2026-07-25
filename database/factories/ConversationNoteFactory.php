<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConversationNote> */
class ConversationNoteFactory extends Factory
{
    protected $model = ConversationNote::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'user_id' => User::factory(),
            'body' => 'Nota interna de teste',
        ];
    }
}
