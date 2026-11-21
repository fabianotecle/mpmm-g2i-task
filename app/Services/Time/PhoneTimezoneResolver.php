<?php

namespace App\Services\Time;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberToTimeZonesMapper;
use libphonenumber\PhoneNumberUtil;

class PhoneTimezoneResolver
{
    public function resolve(string $phone): string
    {
        $phone = preg_replace('/\D+/', '', $phone) ?: '';

        return match (true) {
            str_starts_with($phone, '351') => 'Europe/Lisbon',
            str_starts_with($phone, '44') => 'Europe/London',
            str_starts_with($phone, '1') => $this->northAmericanTimezone($phone),
            str_starts_with($phone, '55') => $this->brazilianTimezone($phone),
            default => $this->mappedTimezone($phone),
        };
    }

    private function northAmericanTimezone(string $phone): string
    {
        if (strlen($phone) !== 11) {
            return 'UTC';
        }

        // NANPA assigns 689 to the 407/321 Orlando overlay. The bundled
        // prefix map currently returns Chicago for some 689 exchanges.
        if (substr($phone, 1, 3) === '689') {
            return 'America/New_York';
        }

        return $this->mappedTimezone($phone);
    }

    private function mappedTimezone(string $phone): string
    {
        if (strlen($phone) < 8 || strlen($phone) > 15) {
            return 'UTC';
        }

        try {
            $number = PhoneNumberUtil::getInstance()->parse('+'.$phone, null);
            $zones = PhoneNumberToTimeZonesMapper::getInstance()->getTimeZonesForGeographicalNumber($number);
            $zones = array_values(array_filter($zones, fn (string $zone) => in_array($zone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)));

            // A phone prefix can cross time zones. Do not invent a local clock in that case.
            return count($zones) === 1
                ? match ($zones[0]) {
                    'America/Buenos_Aires' => 'America/Argentina/Buenos_Aires',
                    default => $zones[0],
                }
                : 'UTC';
        } catch (NumberParseException) {
            return 'UTC';
        }
    }

    private function brazilianTimezone(string $phone): string
    {
        $ddd = substr($phone, 2, 2);

        return match (true) {
            in_array($ddd, ['68'], true) => 'America/Rio_Branco',
            in_array($ddd, ['69'], true) => 'America/Porto_Velho',
            in_array($ddd, ['92', '97'], true) => 'America/Manaus',
            in_array($ddd, ['95'], true) => 'America/Boa_Vista',
            in_array($ddd, ['91', '93', '94'], true) => 'America/Belem',
            in_array($ddd, ['63'], true) => 'America/Araguaina',
            in_array($ddd, ['65', '66'], true) => 'America/Cuiaba',
            in_array($ddd, ['67'], true) => 'America/Campo_Grande',
            default => 'America/Sao_Paulo',
        };
    }
}
