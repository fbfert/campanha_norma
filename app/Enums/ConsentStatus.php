<?php

namespace App\Enums;

enum ConsentStatus: string
{
    case NotInformed = 'not_informed';
    case Pending = 'pending';
    case Granted = 'granted';
    case Revoked = 'revoked';
    case NotRequired = 'not_required';

    public function label(): string
    {
        return match ($this) {
            self::NotInformed => 'Nao informado',
            self::Pending => 'Pendente',
            self::Granted => 'Concedido',
            self::Revoked => 'Revogado',
            self::NotRequired => 'Nao requerido',
        };
    }
}
