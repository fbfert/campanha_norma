<?php

namespace App\Enums;

enum ReportExportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Processing => 'Processando',
            self::Completed => 'Concluida',
            self::Failed => 'Falhou',
            self::Expired => 'Expirada',
            self::Cancelled => 'Cancelada',
        };
    }
}
