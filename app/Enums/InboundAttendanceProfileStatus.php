<?php

namespace App\Enums;

enum InboundAttendanceProfileStatus: string
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

    /**
     * Só perfil ativo atende. Rascunho ainda está sendo escrito, pausado foi
     * desligado de propósito e arquivado saiu de uso — nenhum dos três deve
     * responder a quem escreve.
     */
    public function isRunnable(): bool
    {
        return $this === self::Active;
    }
}
