<?php

namespace Database\Factories;

use App\Models\ConversationFlow;
use App\Models\ConversationFlowQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConversationFlowQuestion> */
class ConversationFlowQuestionFactory extends Factory
{
    protected $model = ConversationFlowQuestion::class;

    public function definition(): array
    {
        return [
            'conversation_flow_id' => ConversationFlow::factory(),
            'internal_title' => 'Pergunta '.fake()->unique()->numerify('###'),
            'text' => 'O que a Professora Norma pode fazer para melhorar nosso Estado?',
            'category' => null,
            'weight' => 1,
            'display_order' => 0,
            'is_active' => true,
            'version' => 1,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
