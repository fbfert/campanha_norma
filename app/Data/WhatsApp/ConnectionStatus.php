<?php

namespace App\Data\WhatsApp;

use App\Enums\WhatsAppConnectionStatus;
use Carbon\CarbonImmutable;

readonly class ConnectionStatus
{
    public function __construct(
        public WhatsAppConnectionStatus $status,
        public ?string $phoneNumber = null,
        public ?string $displayName = null,
        public ?CarbonImmutable $connectedAt = null,
        public ?CarbonImmutable $lastActivityAt = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public bool $browserReady = false,
        public bool $sessionAvailable = false,
        public array $metadata = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            status: WhatsAppConnectionStatus::tryFrom((string) ($data['status'] ?? 'service_error')) ?? WhatsAppConnectionStatus::ServiceError,
            phoneNumber: $data['phone_number'] ?? null,
            displayName: $data['display_name'] ?? null,
            connectedAt: self::date($data['connected_at'] ?? null),
            lastActivityAt: self::date($data['last_activity_at'] ?? null),
            errorCode: $data['error_code'] ?? null,
            errorMessage: $data['error_message'] ?? null,
            browserReady: (bool) ($data['browser_ready'] ?? false),
            sessionAvailable: (bool) ($data['session_available'] ?? false),
            metadata: collect($data)->except(['status', 'phone_number', 'display_name', 'connected_at', 'last_activity_at', 'error_code', 'error_message'])->all(),
        );
    }

    private static function date(?string $value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::parse($value) : null;
    }
}
