<?php

namespace App\Services\KeywordCampaigns;

use App\Enums\KeywordEnrollmentOutcome;
use App\Models\KeywordCampaignParticipation;

/**
 * O resultado de uma tentativa de inscrição.
 *
 * Carrega a participação quando existe uma — inclusive no caso de quem já
 * estava inscrita, porque quem chama precisa dela para registrar o evento e
 * para decidir o texto da resposta.
 */
readonly class EnrollmentResult
{
    public function __construct(
        public KeywordEnrollmentOutcome $outcome,
        public ?KeywordCampaignParticipation $participation = null,
    ) {}

    public static function de(KeywordEnrollmentOutcome $outcome, ?KeywordCampaignParticipation $participation = null): self
    {
        return new self($outcome, $participation);
    }

    public function registrou(): bool
    {
        return $this->outcome->registrou();
    }

    public function atendeuAMensagem(): bool
    {
        return $this->outcome->atendeuAMensagem();
    }
}
