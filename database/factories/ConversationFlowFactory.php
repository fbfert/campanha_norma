<?php

namespace Database\Factories;

use App\Enums\ConversationFlowStatus;
use App\Enums\ConversationQuestionOrder;
use App\Models\ConversationFlow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConversationFlow> */
class ConversationFlowFactory extends Factory
{
    protected $model = ConversationFlow::class;

    public function definition(): array
    {
        return [
            'name' => 'Pesquisa '.fake()->unique()->word(),
            'description' => 'Fluxo de pesquisa conversacional de teste.',
            'status' => ConversationFlowStatus::Active,
            'presentation_template_id' => null,
            'presentation_text' => 'Ola! Podemos fazer uma pergunta rapida?',
            'thank_you_text' => 'Obrigado pela sua participação.',
            'permission_denied_text' => 'Tudo bem, obrigado pela atenção.',
            'max_main_questions' => 1,
            'question_order' => ConversationQuestionOrder::Sorteio,
            'max_followups' => 0,
            'validity_hours' => 48,
            'transparency_enabled' => true,
            'transparency_text' => 'Mensagem automática.',
            'created_by' => User::factory(),
        ];
    }

    public function paused(): self
    {
        return $this->state(fn (): array => ['status' => ConversationFlowStatus::Paused]);
    }

    public function draft(): self
    {
        return $this->state(fn (): array => ['status' => ConversationFlowStatus::Draft]);
    }
}
