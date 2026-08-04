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
            'UNAUTHORIZED_SERVICE_REQUEST' => 'A autenticação interna com o serviço do WhatsApp falhou.',
            'SERVICE_UNAVAILABLE' => 'O serviço de conexão com o WhatsApp esta indisponível. Verifique o processo do Node.js na VPS.',
            // Serviço de pé e travado pede outra ação: o processo está lá, e
            // reiniciá-lo é justamente o que resolve. Dizer "indisponível"
            // manda conferir se ele existe, e a conferência dá tudo certo.
            'SERVICE_TIMEOUT' => 'O serviço do WhatsApp não respondeu a tempo. Ele pode estar de pé e travado: reinicie o serviço do Node.js na VPS.',
            'QR_EXPIRED' => 'O QR Code expirou. Gere um novo QR Code.',
            'SESSION_EXPIRED' => 'A sessão do WhatsApp expirou. Gere um novo QR Code para autenticar novamente.',
            'WHATSAPP_NOT_CONNECTED' => 'A conta do WhatsApp não esta conectada.',
            'INVALID_PHONE' => 'O telefone informado e inválido.',
            'EMPTY_MESSAGE' => 'A mensagem não pode ficar vazia.',
            'DUPLICATE_REQUEST' => 'Esta solicitação de envio já foi processada.',
            default => $this->getMessage() ?: 'Não foi possível comunicar com o serviço do WhatsApp.',
        };
    }
}
