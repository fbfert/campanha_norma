<?php

namespace App\Services\Contacts;

use App\Services\SystemSettingService;
use App\Support\PhoneNormalizationResult;

class PhoneNormalizerService
{
    public function __construct(private readonly SystemSettingService $settings) {}

    public function normalize(?string $phone): PhoneNormalizationResult
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if ($digits === '') {
            return new PhoneNormalizationResult(null, 'Telefone e obrigatorio.');
        }

        $defaultCode = (string) $this->settings->get('contacts.default_country_code', '55');

        if (! str_starts_with($digits, $defaultCode)) {
            if (strlen($digits) === 10 || strlen($digits) === 11) {
                $digits = $defaultCode.$digits;
            }
        }

        if (strlen($digits) < 12 || strlen($digits) > 15) {
            return new PhoneNormalizationResult(null, 'Telefone invalido. Informe DDI, DDD e numero.');
        }

        if (str_starts_with($digits, '55')) {
            $local = substr($digits, 2);
            if (strlen($local) < 10 || strlen($local) > 11 || preg_match('/^(\d)\1+$/', $local)) {
                return new PhoneNormalizationResult(null, 'Telefone brasileiro invalido.');
            }
        }

        return new PhoneNormalizationResult($digits);
    }
}
