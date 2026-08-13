<?php

namespace App\Enums;

enum MediaStorageStatus: string
{
    /** Ainda não foi buscada: ninguém precisou dela. */
    case Pending = 'pending';

    /** Arquivo em disco, pronto para exibir e para a visão ler. */
    case Stored = 'stored';

    /**
     * A sessão não devolveu o arquivo.
     *
     * Não é erro de operação: o WhatsApp Web guarda mídia por tempo limitado, e
     * a de uma conversa antiga simplesmente não volta. Registrar isso é o que
     * permite a tela dizer "não foi possível recuperar" em vez de tentar para
     * sempre e mostrar um quadrado quebrado.
     */
    case Unavailable = 'unavailable';

    /** Passou do teto de tamanho configurado. */
    case TooLarge = 'too_large';

    /** O arquivo foi apagado pelo prazo de retenção; o registro fica. */
    case Purged = 'purged';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Não baixada',
            self::Stored => 'Disponível',
            self::Unavailable => 'Não recuperada',
            self::TooLarge => 'Grande demais',
            self::Purged => 'Expirada',
        };
    }

    public function isReadable(): bool
    {
        return $this === self::Stored;
    }

    /**
     * Vale a pena tentar de novo?
     *
     * Grande demais não muda com o tempo. Indisponível pode mudar — mídia volta
     * quando a sessão reconecta com o histórico —, mas o teto de tentativas
     * cuida de não insistir para sempre.
     */
    public function isRetryable(): bool
    {
        return $this === self::Pending || $this === self::Unavailable;
    }
}
