<?php

namespace App\Services\Phone;

class PhoneNumberNormalizer
{
    private const BRAZILIAN_AREA_CODES = [
        '11', '12', '13', '14', '15', '16', '17', '18', '19',
        '21', '22', '24', '27', '28',
        '31', '32', '33', '34', '35', '37', '38',
        '41', '42', '43', '44', '45', '46', '47', '48', '49',
        '51', '53', '54', '55',
        '61', '62', '63', '64', '65', '66', '67', '68', '69',
        '71', '73', '74', '75', '77', '79',
        '81', '82', '83', '84', '85', '86', '87', '88', '89',
        '91', '92', '93', '94', '95', '96', '97', '98', '99',
    ];

    public function normalize(string $phone, string $referencePhone): ?string
    {
        $explicitInternational = str_starts_with(trim($phone), '+') || str_starts_with(trim($phone), '00');
        $phone = $this->digits($phone);
        $reference = $this->digits($referencePhone);

        if ($phone === '') {
            return null;
        }

        if (str_starts_with($phone, '00')) {
            $phone = substr($phone, 2);
        }

        if (! $explicitInternational && str_starts_with($reference, '55')
            && strlen($phone) === 11 && substr($phone, 2, 1) === '9'
            && $this->isValidBrazilianAreaCode(substr($phone, 0, 2))) {
            return '55'.$phone;
        }

        if ($known = $this->normalizeKnownNumber($phone)) {
            return $known;
        }

        return match (true) {
            str_starts_with($reference, '55') => $this->normalizeBrazilian($phone, $reference),
            str_starts_with($reference, '351') => strlen($phone) === 9 ? '351'.$phone : null,
            str_starts_with($reference, '1') => strlen($phone) === 7 ? substr($reference, 0, 4).$phone : (strlen($phone) === 10 ? '1'.$phone : null),
            str_starts_with($reference, '44') => $this->normalizeUk($phone),
            default => null,
        };
    }

    public function normalizeKnownNumber(string $phone): ?string
    {
        $phone = $this->digits($phone);

        if (str_starts_with($phone, '00')) {
            $phone = substr($phone, 2);
        }

        if (str_starts_with($phone, '55') && in_array(strlen($phone), [12, 13], true)) {
            return $this->normalizeBrazilianWithCountryCode($phone);
        }

        return $this->hasSupportedCountryCode($phone) ? $phone : null;
    }

    private function normalizeBrazilian(string $phone, string $reference): ?string
    {
        if (strlen($phone) === 8) {
            $phone = substr($reference, 2, 2).'9'.$phone;
        } elseif (strlen($phone) === 9) {
            $phone = substr($reference, 2, 2).$phone;
        } elseif (strlen($phone) === 10) {
            $phone = substr($phone, 0, 2).'9'.substr($phone, 2);
        }

        if (strlen($phone) !== 11 || ! $this->isValidBrazilianAreaCode(substr($phone, 0, 2))) {
            return null;
        }

        return '55'.$phone;
    }

    private function normalizeBrazilianWithCountryCode(string $phone): ?string
    {
        if (strlen($phone) === 12) {
            $phone = substr($phone, 0, 4).'9'.substr($phone, 4);
        }

        return $this->isValidBrazilianAreaCode(substr($phone, 2, 2)) ? $phone : null;
    }

    private function normalizeUk(string $phone): ?string
    {
        if (str_starts_with($phone, '0')) {
            $phone = substr($phone, 1);
        }

        return strlen($phone) === 10 ? '44'.$phone : null;
    }

    private function hasSupportedCountryCode(string $phone): bool
    {
        return (str_starts_with($phone, '55') && in_array(strlen($phone), [12, 13], true))
            || (str_starts_with($phone, '351') && strlen($phone) === 12)
            || (str_starts_with($phone, '1') && strlen($phone) === 11)
            || (str_starts_with($phone, '44') && strlen($phone) === 12);
    }

    private function isValidBrazilianAreaCode(string $areaCode): bool
    {
        return in_array($areaCode, self::BRAZILIAN_AREA_CODES, true);
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?: '';
    }
}
