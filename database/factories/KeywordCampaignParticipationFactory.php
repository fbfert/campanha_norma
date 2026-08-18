<?php

namespace Database\Factories;

use App\Enums\KeywordParticipationEligibility;
use App\Enums\KeywordParticipationStatus;
use App\Models\Contact;
use App\Models\ConversationMessage;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignParticipation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KeywordCampaignParticipation> */
class KeywordCampaignParticipationFactory extends Factory
{
    protected $model = KeywordCampaignParticipation::class;

    public function definition(): array
    {
        return [
            'keyword_campaign_id' => KeywordCampaign::factory(),
            'contact_id' => Contact::factory(),
            'conversation_message_id' => ConversationMessage::factory(),
            'matched_keyword' => 'sorteio',
            'captured_name' => 'Maria da Silva',
            'status' => KeywordParticipationStatus::Valida,
            'eligibility' => KeywordParticipationEligibility::NaoVerificada,
        ];
    }

    public function alunoConfirmado(): static
    {
        return $this->state(fn (): array => [
            'eligibility' => KeywordParticipationEligibility::AlunoConfirmado,
            'reviewed_at' => now(),
        ]);
    }

    public function semNome(): static
    {
        return $this->state(fn (): array => [
            'captured_name' => null,
            'status' => KeywordParticipationStatus::SemNome,
        ]);
    }

    public function invalidada(): static
    {
        return $this->state(fn (): array => [
            'status' => KeywordParticipationStatus::Invalidada,
            'invalidated_at' => now(),
            'invalidation_reason' => 'Participação de teste invalidada.',
        ]);
    }
}
