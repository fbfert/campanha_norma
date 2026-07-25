<?php

namespace App\Enums;

enum ConversationMessageStatus: string
{
    case Received = 'received';
    case Pending = 'pending';
    case Processing = 'processing';
    case Sent = 'sent';
    case Failed = 'failed';
    case Unknown = 'unknown';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Recebida',
            self::Pending => 'Pendente',
            self::Processing => 'Processando',
            self::Sent => 'Enviada',
            self::Failed => 'Falhou',
            self::Unknown => 'Resultado incerto',
            self::Cancelled => 'Cancelada',
        };
    }
}
