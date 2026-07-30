<?php

namespace App\Enums;

enum PermissionResponseClassification: string
{
    case PermissionYes = 'permission_yes';
    case PermissionNo = 'permission_no';
    case OptOut = 'opt_out';
    case Ambiguous = 'ambiguous';

    public function label(): string
    {
        return match ($this) {
            self::PermissionYes => 'Permissão concedida',
            self::PermissionNo => 'Permissão negada',
            self::OptOut => 'Pedido de não contatar',
            self::Ambiguous => 'Ambíguo',
        };
    }
}
