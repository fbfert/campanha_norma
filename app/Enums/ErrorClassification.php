<?php

namespace App\Enums;

enum ErrorClassification: string
{
    case Temporary = 'temporary';
    case Permanent = 'permanent';
    case Structural = 'structural';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Temporary => 'Temporário',
            self::Permanent => 'Permanente',
            self::Structural => 'Estrutural',
            self::Unknown => 'Desconhecido',
        };
    }
}
