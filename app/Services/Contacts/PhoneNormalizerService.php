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
            return new PhoneNormalizationResult(null, 'Telefone e obrigatório.');
        }

        $defaultCode = (string) $this->settings->get('contacts.default_country_code', '55');

        if (! str_starts_with($digits, $defaultCode)) {
            if (strlen($digits) === 10 || strlen($digits) === 11) {
                $digits = $defaultCode.$digits;
            }
        }

        if (strlen($digits) < 12 || strlen($digits) > 15) {
            return new PhoneNormalizationResult(null, 'Telefone inválido. Informe DDI, DDD e número.');
        }

        if (str_starts_with($digits, '55')) {
            $local = substr($digits, 2);
            if (strlen($local) < 10 || strlen($local) > 11 || preg_match('/^(\d)\1+$/', $local)) {
                return new PhoneNormalizationResult(null, 'Telefone brasileiro inválido.');
            }
        }

        return new PhoneNormalizationResult($digits);
    }

    /**
     * Números móveis brasileiros podem circular com ou sem o nono digito
     * (ex: 5549999592392 x 554999592392). Retorna a forma alternativa do
     * número informado para permitir casamento entre as duas variantes.
     */
    public function alternateBrazilianMobileDigits(string $normalized): ?string
    {
        if (! str_starts_with($normalized, '55') || strlen($normalized) < 12) {
            return null;
        }

        $ddd = substr($normalized, 2, 2);
        $local = substr($normalized, 4);

        if (strlen($local) === 8) {
            return '55'.$ddd.'9'.$local;
        }

        if (strlen($local) === 9 && $local[0] === '9') {
            return '55'.$ddd.substr($local, 1);
        }

        return null;
    }
}
