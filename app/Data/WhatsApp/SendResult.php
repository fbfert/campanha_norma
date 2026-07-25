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
            sentAt: isset($data['sent_at']) ? CarbonImmutable::parse($data['sent_at']) : null,
            errorCode: $data['error_code'] ?? null,
            errorMessage: $data['error_message'] ?? null,
        );
    }
}
