<?php

namespace App\Enums;

enum MessageBatchStatus: string
{
    case Draft = 'draft';
    case Validating = 'validating';
    case Ready = 'ready';
    case Queued = 'queued';
    case Processing = 'processing';
    case Pausing = 'pausing';
    case Paused = 'paused';
    case Stopping = 'stopping';
    case Stopped = 'stopped';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Validating => 'Validando',
            self::Ready => 'Preparado',
            self::Queued => 'Na fila',
            self::Processing => 'Processando',
            self::Pausing => 'Pausando',
            self::Paused => 'Pausado',
            self::Stopping => 'Parando',
            self::Stopped => 'Parado',
            self::Completed => 'Concluido',
            self::CompletedWithErrors => 'Concluido com erros',
            self::Failed => 'Falhou',
            self::Cancelled => 'Cancelado',
        };
    }
}
