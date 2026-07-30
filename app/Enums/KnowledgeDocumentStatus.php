<?php

namespace App\Enums;

/**
 * Ciclo de vida do documento.
 *
 * A separação entre `ready` e `approved` e deliberada: indexar e uma operação
 * técnica que a fila faz sozinha, aprovar e uma afirmação de que o conteúdo pode
 * ser dito a uma pessoa. Somente `approved` e recuperável.
 */
enum KnowledgeDocumentStatus: string
{
    case Draft = 'draft';
    case Processing = 'processing';
    case Ready = 'ready';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Obsolete = 'obsolete';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Processing => 'Processando',
            self::Ready => 'Pronto, aguardando aprovação',
            self::Approved => 'Aprovado',
            self::Rejected => 'Rejeitado',
            self::Obsolete => 'Obsoleto',
            self::Failed => 'Falhou',
        };
    }

    public function isRetrievable(): bool
    {
        return $this === self::Approved;
    }

    public function canBeApproved(): bool
    {
        return $this === self::Ready || $this === self::Rejected;
    }

    public function canBeReprocessed(): bool
    {
        return $this !== self::Processing;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
