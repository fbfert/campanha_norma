<?php

namespace App\Enums;

/**
 * Feedback operacional. Coletado para leitura humana e para as métricas da
 * subetapa seguinte: nenhum processo automático ajusta prompt, modelo,
 * threshold ou allowlist a partir deste valor.
 */
enum SuggestionFeedback: string
{
    case Good = 'good';
    case Bad = 'bad';
    case Inappropriate = 'inappropriate';

    public function label(): string
    {
        return match ($this) {
            self::Good => 'Boa',
            self::Bad => 'Ruim',
            self::Inappropriate => 'Inadequada',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
