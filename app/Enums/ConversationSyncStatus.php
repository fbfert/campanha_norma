<?php

namespace App\Enums;

enum ConversationSyncStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Running => 'Em execucao',
            self::Completed => 'Concluida',
            self::CompletedWithErrors => 'Concluida com erros',
            self::Failed => 'Falhou',
            self::Cancelled => 'Cancelada',
        };
    }
}
