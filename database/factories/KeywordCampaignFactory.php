<?php

namespace Database\Factories;

use App\Enums\KeywordCampaignStatus;
use App\Models\KeywordCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KeywordCampaign> */
class KeywordCampaignFactory extends Factory
{
    protected $model = KeywordCampaign::class;

    public function definition(): array
    {
        return [
            'name' => 'Sorteio de cursos',
            'description' => null,
            'status' => KeywordCampaignStatus::Ativa,
            // Já normalizadas, como o banco guarda.
            'keywords' => ['sorteio'],
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'participant_limit' => null,
            'hourly_alert_threshold' => null,
            'hourly_alert_raised_at' => null,
            'confirmation_text' => 'Inscrição confirmada! Boa sorte.',
            'already_enrolled_text' => 'Você já está inscrito nesta campanha.',
            'out_of_window_text' => null,
        ];
    }

    public function rascunho(): static
    {
        return $this->state(fn (): array => ['status' => KeywordCampaignStatus::Rascunho]);
    }

    public function encerrada(): static
    {
        return $this->state(fn (): array => [
            'status' => KeywordCampaignStatus::Encerrada,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);
    }
}
