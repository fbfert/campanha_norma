<?php

namespace App\Enums;

enum ReplySuggestionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Sent = 'sent';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
    case Expired = 'expired';
    case Blocked = 'blocked';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando aprovação',
            self::Approved => 'Aprovada',
            self::Sent => 'Enviada',
            self::Rejected => 'Rejeitada',
            self::Superseded => 'Obsoleta',
            self::Expired => 'Expirada',
            self::Blocked => 'Bloqueada',
            self::Failed => 'Falhou',
        };
    }

    /**
     * Sugestão viva ocupa a unicidade por mensagem de origem.
     */
    public function isLive(): bool
    {
        return $this === self::Pending || $this === self::Approved;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
