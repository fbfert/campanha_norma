<?php

namespace App\Enums;

enum ClassificationSource: string
{
    case Deterministic = 'deterministic';
    case Ai = 'ai';
    case Human = 'human';

    public function label(): string
    {
        return match ($this) {
            self::Deterministic => 'Regra deterministica',
            self::Ai => 'Inteligencia artificial',
            self::Human => 'Correcao humana',
        };
    }
}
