<?php

namespace App\Services\Reports;

use App\Enums\ErrorClassification;

class ErrorClassificationService
{
    private const TEMPORARY = ['SERVICE_UNAVAILABLE', 'WHATSAPP_DISCONNECTED', 'BROWSER_DISCONNECTED', 'NETWORK_TIMEOUT', 'RATE_LIMITED', 'TEMPORARY_PROVIDER_ERROR', 'SEND_RESULT_UNKNOWN'];

    private const PERMANENT = ['INVALID_PHONE', 'CONTACT_BLOCKED', 'CONTACT_DO_NOT_CONTACT', 'EMPTY_MESSAGE', 'MESSAGE_TOO_LONG', 'UNSUPPORTED_DESTINATION', 'PERMANENT_PROVIDER_ERROR', 'CONTACT_BECAME_INELIGIBLE', 'BATCH_STOPPED', 'RECIPIENT_CANCELLED'];

    private const STRUCTURAL = ['INVALID_BATCH_STATE', 'MISSING_PROVIDER_CONFIGURATION', 'INVALID_SENDING_SETTINGS', 'DATABASE_ERROR', 'REDIS_ERROR'];

    public function classify(?string $code): ErrorClassification
    {
        if (! $code) {
            return ErrorClassification::Unknown;
        }

        return match (true) {
            in_array($code, self::TEMPORARY, true) => ErrorClassification::Temporary,
            in_array($code, self::PERMANENT, true) => ErrorClassification::Permanent,
            in_array($code, self::STRUCTURAL, true) => ErrorClassification::Structural,
            default => ErrorClassification::Unknown,
        };
    }
}
