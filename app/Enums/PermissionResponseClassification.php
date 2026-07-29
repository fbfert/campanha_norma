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
            self::PermissionYes => 'Permissao concedida',
            self::PermissionNo => 'Permissao negada',
            self::OptOut => 'Pedido de nao contatar',
            self::Ambiguous => 'Ambiguo',
        };
    }
}
