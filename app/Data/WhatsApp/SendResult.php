<?php

namespace App\Data\WhatsApp;

use Carbon\CarbonImmutable;

readonly class SendResult
{
    public function __construct(
        public string $requestId,
        public string $status,
        public ?string $externalMessageId = null,
        public ?CarbonImmutable $sentAt = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            requestId: (string) ($data['request_id'] ?? ''),
            status: (string) ($data['status'] ?? 'failed'),
            externalMessageId: $data['external_message_id'] ?? null,
            // O serviço Node responde em UTC. Sem converter, a mensagem que
            // saiu às 19h era gravada como 22h — três horas à frente do
            // `created_at` que o Laravel carimbou no mesmo instante.
            sentAt: isset($data['sent_at'])
                ? CarbonImmutable::parse($data['sent_at'])->setTimezone(config('app.timezone'))
                : null,
            errorCode: $data['error_code'] ?? null,
            errorMessage: $data['error_message'] ?? null,
        );
    }
}
