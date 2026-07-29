<?php

namespace App\Enums;

enum AiRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case InvalidOutput = 'invalid_output';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Running => 'Em execucao',
            self::Succeeded => 'Concluida',
            self::Failed => 'Falhou',
            self::InvalidOutput => 'Saida invalida',
            self::Skipped => 'Ignorada',
        };
    }

    public function isFinal(): bool
    {
        return $this !== self::Pending && $this !== self::Running;
    }
}
