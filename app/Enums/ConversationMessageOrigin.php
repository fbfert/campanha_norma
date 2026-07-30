<?php

namespace App\Enums;

enum ConversationMessageOrigin: string
{
    case Manual = 'manual';
    case Automation = 'automation';
    case ApprovedAi = 'approved_ai';
    case Incoming = 'incoming';
    case Sync = 'sync';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Automation => 'Automática',
            self::ApprovedAi => 'Sugerida por IA',
            self::Incoming => 'Recebida',
            self::Sync => 'Sincronizada',
        };
    }
}
