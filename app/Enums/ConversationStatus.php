<?php

namespace App\Enums;

enum ConversationStatus: string
{
    case New = 'new';
    case Open = 'open';
    case WaitingOperator = 'waiting_operator';
    case WaitingContact = 'waiting_contact';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Archived = 'archived';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Nova',
            self::Open => 'Aberta',
            self::WaitingOperator => 'Aguardando operador',
            self::WaitingContact => 'Aguardando contato',
            self::Resolved => 'Resolvida',
            self::Closed => 'Fechada',
            self::Archived => 'Arquivada',
            self::Blocked => 'Bloqueada',
        };
    }
}
