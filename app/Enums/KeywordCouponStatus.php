<?php

namespace App\Enums;

enum KeywordCouponStatus: string
{
    case Disponivel = 'disponivel';
    case Atribuido = 'atribuido';
    case Entregue = 'entregue';

    public function label(): string
    {
        return match ($this) {
            self::Disponivel => 'Disponível',
            self::Atribuido => 'Atribuído',
            self::Entregue => 'Entregue',
        };
    }
}
