<?php

namespace App\Enums;

enum WhatsAppTestMessageStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Processing => 'Processando',
            self::Sent => 'Enviada',
            self::Failed => 'Falhou',
            self::Cancelled => 'Cancelada',
        };
    }
}
