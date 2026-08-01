<?php

namespace App\Enums;

enum MessageRecipientProcessingStatus: string
{
    case Eligible = 'eligible';
    case Pending = 'pending';
    case WaitingSchedule = 'waiting_schedule';
    case WaitingMinuteLimit = 'waiting_minute_limit';

    // Separado do limite por minuto porque a causa e outra e o ajuste e em
    // outro campo. Enquanto os dois dividiam o mesmo nome, quem via "aguardando
    // limite por minuto" com o limite folgado procurava no lugar errado.
    case WaitingMinimumInterval = 'waiting_minimum_interval';

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
            self::WaitingSchedule => 'Aguardando horário',
            self::WaitingMinuteLimit => 'Aguardando limite por minuto',
            self::WaitingMinimumInterval => 'Aguardando intervalo mínimo',
            self::WaitingHourLimit => 'Aguardando limite por hora',
            self::WaitingDayLimit => 'Aguardando limite diário',
            self::Queued => 'Na fila',
            self::Processing => 'Processando',
            self::Sent => 'Enviado',
            self::RetryWait => 'Aguardando nova tentativa',
            self::FailedTemporary => 'Falha temporária',
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
            self::WaitingMinimumInterval,
            self::WaitingHourLimit,
            self::WaitingDayLimit,
            self::Queued,
            self::Processing,
            self::RetryWait,
            self::FailedTemporary,
        ], true);
    }
}
