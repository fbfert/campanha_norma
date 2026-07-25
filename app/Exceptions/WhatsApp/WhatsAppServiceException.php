<?php

namespace App\Exceptions\WhatsApp;

use RuntimeException;

class WhatsAppServiceException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode = 0,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function userMessage(): string
    {
        return match ($this->errorCode) {
            'UNAUTHORIZED_SERVICE_REQUEST' => 'A autenticacao interna com o servico do WhatsApp falhou.',
            'SERVICE_UNAVAILABLE' => 'O servico de conexao com o WhatsApp esta indisponivel. Verifique o processo do Node.js na VPS.',
            'QR_EXPIRED' => 'O QR Code expirou. Gere um novo QR Code.',
            'SESSION_EXPIRED' => 'A sessao do WhatsApp expirou. Gere um novo QR Code para autenticar novamente.',
            'WHATSAPP_NOT_CONNECTED' => 'A conta do WhatsApp nao esta conectada.',
            'INVALID_PHONE' => 'O telefone informado e invalido.',
            'EMPTY_MESSAGE' => 'A mensagem nao pode ficar vazia.',
            'DUPLICATE_REQUEST' => 'Esta solicitacao de envio ja foi processada.',
            default => $this->getMessage() ?: 'Nao foi possivel comunicar com o servico do WhatsApp.',
        };
    }
}
