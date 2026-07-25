<?php

namespace App\Enums;

enum MessageRecipientProcessingStatus: string
{
    case Eligible = 'eligible';
    case Pending = 'pending';
    case WaitingSchedule = 'waiting_schedule';
    case WaitingMinuteLimit = 'waiting_minute_limit';
    case WaitingHourLimit = 'waiting_hour_limit';
    case WaitingDayLimit = 'waiting_day_limit';
    case Queued = 'queued';
    case Processing = 'processing';
    case Sent = 'sent';
    case RetryWait = 'retry_wait';
    case FailedTemporary = 'failed_temporary';
    case FailedPermanent = 'failed_permanent';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Eligible => 'Apto',
            self::Pending => 'Pendente',
            self::WaitingSchedule => 'Aguardando horario',
            self::WaitingMinuteLimit => 'Aguardando limite por minuto',
            self::WaitingHourLimit => 'Aguardando limite por hora',
            self::WaitingDayLimit => 'Aguardando limite diario',
            self::Queued => 'Na fila',
            self::Processing => 'Processando',
            self::Sent => 'Enviado',
            self::RetryWait => 'Aguardando nova tentativa',
            self::FailedTemporary => 'Falha temporaria',
            self::FailedPermanent => 'Falha permanente',
            self::Cancelled => 'Cancelado',
            self::Skipped => 'Ignorado',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [
            self::Pending,
            self::WaitingSchedule,
            self::WaitingMinuteLimit,
            self::WaitingHourLimit,
            self::WaitingDayLimit,
            self::Queued,
            self::Processing,
            self::RetryWait,
            self::FailedTemporary,
        ], true);
    }
}
