<?php

namespace App\Enums;

enum ConversationMessageOrigin: string
{
    case Manual = 'manual';
    case Automation = 'automation';
    case Incoming = 'incoming';
    case Sync = 'sync';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Automation => 'Automatica',
            self::Incoming => 'Recebida',
            self::Sync => 'Sincronizada',
        };
    }
}
