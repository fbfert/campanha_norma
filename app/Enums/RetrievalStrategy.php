<?php

namespace App\Enums;

/**
 * Estratégia de recuperação.
 *
 * O padrão e `lexical` porque ela não depende de credencial de embedding: a base
 * funciona, e testável e e homologável sem nenhuma chamada externa. A vetorial e
 * um ganho de qualidade que se liga depois.
 */
enum RetrievalStrategy: string
{
    case Lexical = 'lexical';
    case Vector = 'vector';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::Lexical => 'Léxica',
            self::Vector => 'Vetorial',
            self::Hybrid => 'Híbrida',
        };
    }

    public function usesEmbeddings(): bool
    {
        return $this === self::Vector || $this === self::Hybrid;
    }

    public function usesLexical(): bool
    {
        return $this === self::Lexical || $this === self::Hybrid;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
