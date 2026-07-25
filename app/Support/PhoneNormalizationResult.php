<?php

namespace App\Support;

readonly class PhoneNormalizationResult
{
    public function __construct(
        public ?string $normalized,
        public ?string $error = null,
    ) {}

    public function valid(): bool
    {
        return $this->error === null && $this->normalized !== null;
    }
}
