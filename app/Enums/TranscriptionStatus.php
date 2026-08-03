<?php

namespace App\Enums;

enum TranscriptionStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Empty = 'empty';
    case Failed = 'failed';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando transcrição',
            self::Succeeded => 'Transcrito',
            self::Empty => 'Áudio sem fala reconhecível',
            self::Failed => 'Falha na transcrição',
            self::Superseded => 'Substituída por outra transcrição',
        };
    }

    /**
     * Transcrição que o fluxo pode usar como se fosse texto da pessoa.
     *
     * `empty` fica de fora de propósito: áudio sem fala reconhecível não e
     * resposta, e tratar silêncio como opinião inventaria dado de pesquisa.
     */
    public function usableAsAnswer(): bool
    {
        return $this === self::Succeeded;
    }
}
