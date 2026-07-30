<?php

namespace App\Enums;

enum ReplySuggestionAction: string
{
    case SuggestReply = 'suggest_reply';
    case ThankAndComplete = 'thank_and_complete';
    case RequestClarification = 'request_clarification';
    case HandoffHuman = 'handoff_human';
    case NoReply = 'no_reply';
    case OptOut = 'opt_out';

    public function label(): string
    {
        return match ($this) {
            self::SuggestReply => 'Sugerir resposta',
            self::ThankAndComplete => 'Agradecer e concluir',
            self::RequestClarification => 'Pedir esclarecimento',
            self::HandoffHuman => 'Encaminhar para humano',
            self::NoReply => 'Nao responder',
            self::OptOut => 'Pedido de parada',
        };
    }

    /** Acoes que produzem texto para o contato. */
    public function producesText(): bool
    {
        return in_array($this, [self::SuggestReply, self::ThankAndComplete, self::RequestClarification], true);
    }

    /** Acoes que contam como aprofundamento quando enviadas. */
    public function isDeepening(): bool
    {
        return $this === self::SuggestReply || $this === self::RequestClarification;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
