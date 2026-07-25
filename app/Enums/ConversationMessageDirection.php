<?php

namespace App\Enums;

enum ConversationMessageDirection: string
{
    case Incoming = 'incoming';
    case Outgoing = 'outgoing';
    case System = 'system';
    case InternalNote = 'internal_note';

    public function label(): string
    {
        return match ($this) {
            self::Incoming => 'Recebida',
            self::Outgoing => 'Enviada manualmente',
            self::System => 'Sistema',
            self::InternalNote => 'Nota interna',
        };
    }
}
