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
            self::Running => 'Em execução',
            self::Succeeded => 'Concluída',
            self::Failed => 'Falhou',
            self::InvalidOutput => 'Saída invalida',
            self::Skipped => 'Ignorada',
        };
    }

    public function isFinal(): bool
    {
        return $this !== self::Pending && $this !== self::Running;
    }
}
