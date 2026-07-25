<?php

namespace App\Enums;

enum ContactImportStatus: string
{
    case Uploaded = 'uploaded';
    case Mapping = 'mapping';
    case Validating = 'validating';
    case Ready = 'ready';
    case Processing = 'processing';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Enviado',
            self::Mapping => 'Mapeamento',
            self::Validating => 'Validando',
            self::Ready => 'Pronto',
            self::Processing => 'Processando',
            self::Completed => 'Concluido',
            self::CompletedWithErrors => 'Concluido com erros',
            self::Failed => 'Falhou',
            self::Cancelled => 'Cancelado',
        };
    }
}
