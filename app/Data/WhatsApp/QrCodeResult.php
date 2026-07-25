<?php

namespace App\Data\WhatsApp;

use App\Enums\WhatsAppConnectionStatus;
use Carbon\CarbonImmutable;

readonly class QrCodeResult
{
    public function __construct(
        public WhatsAppConnectionStatus $status,
        public ?string $qrCode = null,
        public ?CarbonImmutable $generatedAt = null,
        public ?CarbonImmutable $expiresAt = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            status: WhatsAppConnectionStatus::tryFrom((string) ($data['status'] ?? 'service_error')) ?? WhatsAppConnectionStatus::ServiceError,
            qrCode: $data['qr_code'] ?? null,
            generatedAt: isset($data['generated_at']) ? CarbonImmutable::parse($data['generated_at']) : null,
            expiresAt: isset($data['expires_at']) ? CarbonImmutable::parse($data['expires_at']) : null,
            errorCode: $data['error_code'] ?? null,
            errorMessage: $data['error_message'] ?? null,
        );
    }
}
