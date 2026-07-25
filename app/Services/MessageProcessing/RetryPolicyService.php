<?php

namespace App\Services\MessageProcessing;

use App\Enums\RetryBackoffType;
use App\Models\MessageBatchRecipient;
use App\Models\SendingSetting;
use Carbon\CarbonImmutable;

class RetryPolicyService
{
    private const TEMPORARY = [
        'SERVICE_UNAVAILABLE',
        'WHATSAPP_DISCONNECTED',
        'BROWSER_DISCONNECTED',
        'NETWORK_TIMEOUT',
        'RATE_LIMITED',
        'TEMPORARY_PROVIDER_ERROR',
        'SEND_RESULT_UNKNOWN',
    ];

    public function isTemporary(string $errorCode): bool
    {
        return in_array($errorCode, self::TEMPORARY, true);
    }

    public function canRetry(MessageBatchRecipient $recipient, string $errorCode): bool
    {
        return $this->isTemporary($errorCode) && $recipient->attempts < $recipient->max_attempts;
    }

    public function nextAttemptAt(MessageBatchRecipient $recipient, SendingSetting $settings): CarbonImmutable
    {
        $base = max(1, $settings->retry_interval_minutes);
        $attempt = max(1, $recipient->attempts);
        $minutes = match ($settings->retry_backoff_type) {
            RetryBackoffType::Linear => $base * $attempt,
            RetryBackoffType::Exponential => $base * (2 ** ($attempt - 1)),
            default => $base,
        };

        $minutes = min($minutes, 1440);

        return CarbonImmutable::now($settings->timezone)->addMinutes($minutes);
    }
}
