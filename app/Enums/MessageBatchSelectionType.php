<?php

namespace App\Enums;

enum MessageBatchSelectionType: string
{
    case Manual = 'manual';
    case Filtered = 'filtered';
    case RandomSample = 'random_sample';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Filtered => 'Todos os filtrados',
            self::RandomSample => 'Amostra aleatoria',
        };
    }
}
