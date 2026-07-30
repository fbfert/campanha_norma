<?php

namespace App\Enums;

enum MonitoringHealthStatus: string
{
    case Healthy = 'healthy';
    case Warning = 'warning';
    case Critical = 'critical';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Saudável',
            self::Warning => 'Atenção',
            self::Critical => 'Crítico',
            self::Unknown => 'Desconhecido',
        };
    }
}
