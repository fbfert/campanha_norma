<?php

namespace App\Enums;

/**
 * Modos de operação da geração de respostas.
 *
 * Ordenados por permissividade. O modo efetivo e sempre o MENOR entre o global
 * e o do fluxo: um fluxo pode restringir, nunca ampliar.
 */
enum ResponseGenerationMode: string
{
    case Disabled = 'disabled';
    case DraftOnly = 'draft_only';
    case ApprovalRequired = 'approval_required';
    case AutoSendLimited = 'auto_send_limited';

    public function label(): string
    {
        return match ($this) {
            self::Disabled => 'Desligado',
            self::DraftOnly => 'Apenas rascunho',
            self::ApprovalRequired => 'Aprovação obrigatória',
            self::AutoSendLimited => 'Autoenvio limitado',
        };
    }

    /** Quanto maior, mais permissivo. */
    public function rank(): int
    {
        return match ($this) {
            self::Disabled => 0,
            self::DraftOnly => 1,
            self::ApprovalRequired => 2,
            self::AutoSendLimited => 3,
        };
    }

    /**
     * Resolução do modo efetivo. O nulo do fluxo significa herdar o global.
     */
    public static function effective(self $global, ?self $flow): self
    {
        if ($flow === null) {
            return $global;
        }

        return $flow->rank() < $global->rank() ? $flow : $global;
    }

    public function generates(): bool
    {
        return $this !== self::Disabled;
    }

    public function allowsSending(): bool
    {
        return $this === self::ApprovalRequired || $this === self::AutoSendLimited;
    }

    public function allowsAutoSend(): bool
    {
        return $this === self::AutoSendLimited;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
