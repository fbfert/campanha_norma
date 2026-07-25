<?php

namespace App\Data\WhatsApp;

use App\Enums\WhatsAppConnectionStatus;

readonly class ConnectionResult
{
    public function __construct(
        public WhatsAppConnectionStatus $status,
        public ?string $message = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $metadata = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            status: WhatsAppConnectionStatus::tryFrom((string) ($data['status'] ?? 'service_error')) ?? WhatsAppConnectionStatus::ServiceError,
            message: $data['message'] ?? null,
            errorCode: $data['error_code'] ?? null,
            errorMessage: $data['error_message'] ?? null,
            metadata: $data,
        );
    }
}
