<?php

namespace App\Enums;

enum InboundAttendanceOutcome: string
{
    /** Conversa aberta: contato criado, fluxo ativado, mensagem enfileirada. */
    case Started = 'started';

    /** Uma trava recusou. O motivo fica em `reason` e aparece na fila. */
    case Blocked = 'blocked';

    /** Não é caso de atendimento de entrada — já tem fluxo, já foi tratada. */
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Started => 'Iniciada',
            self::Blocked => 'Bloqueada',
            self::Skipped => 'Ignorada',
        };
    }
}
