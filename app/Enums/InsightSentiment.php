<?php

namespace App\Enums;

/**
 * Sentimento descritivo do conteudo expresso.
 *
 * Existe para caracterizar o relato, nunca para classificar a pessoa, ranquear
 * contatos ou orientar persuasao individual.
 */
enum InsightSentiment: string
{
    case Positive = 'positivo';
    case Neutral = 'neutro';
    case Negative = 'negativo';
    case Mixed = 'misto';

    public function label(): string
    {
        return match ($this) {
            self::Positive => 'Positivo',
            self::Neutral => 'Neutro',
            self::Negative => 'Negativo',
            self::Mixed => 'Misto',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
