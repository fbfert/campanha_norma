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
            self::Healthy => 'Saudavel',
            self::Warning => 'Atencao',
            self::Critical => 'Critico',
            self::Unknown => 'Desconhecido',
        };
    }
}
