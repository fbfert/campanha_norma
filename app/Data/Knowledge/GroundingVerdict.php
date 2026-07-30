<?php

namespace App\Data\Knowledge;

use App\Enums\GroundingStatus;

readonly class GroundingVerdict
{
    /**
     * @param  array<int, array<string, mixed>>  $citations  citacoes validadas
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public GroundingStatus $status,
        public array $citations = [],
        public array $errors = [],
        public bool $factual = false,
    ) {}

    public function allowsSending(): bool
    {
        return $this->status->allowsSending();
    }

    public function errorSummary(): ?string
    {
        return $this->errors === [] ? null : mb_substr(implode(', ', $this->errors), 0, 255);
    }
}
