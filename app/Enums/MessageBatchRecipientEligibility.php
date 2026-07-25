<?php

namespace App\Enums;

enum MessageBatchRecipientEligibility: string
{
    case Eligible = 'eligible';
    case Ineligible = 'ineligible';
    case Excluded = 'excluded';

    public function label(): string
    {
        return match ($this) {
            self::Eligible => 'Apto',
            self::Ineligible => 'Nao apto',
            self::Excluded => 'Excluido',
        };
    }
}
