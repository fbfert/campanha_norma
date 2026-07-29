<?php

namespace App\Enums;

enum ConversationFlowStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Active => 'Ativo',
            self::Paused => 'Pausado',
            self::Archived => 'Arquivado',
        };
    }
}
