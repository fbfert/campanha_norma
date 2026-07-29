<?php

namespace App\Enums;

enum InsightUrgency: string
{
    case Low = 'baixa';
    case Medium = 'media';
    case High = 'alta';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Baixa',
            self::Medium => 'Media',
            self::High => 'Alta',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
