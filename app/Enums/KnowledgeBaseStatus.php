<?php

namespace App\Enums;

enum KnowledgeBaseStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Active => 'Ativa',
            self::Inactive => 'Inativa',
            self::Archived => 'Arquivada',
        };
    }

    /**
     * Somente base ativa participa da recuperacao.
     */
    public function isRetrievable(): bool
    {
        return $this === self::Active;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
