<?php

namespace App\Support;

final class BrazilianDocument
{
    public static function isValid(string $document): bool
    {
        $digits = preg_replace('/\D+/', '', $document) ?: '';

        if (! in_array(strlen($digits), [11, 14], true)
            || preg_match('/^(\d)\1+$/', $digits)) {
            return false;
        }

        if (strlen($digits) === 11) {
            for ($position = 9; $position < 11; $position++) {
                $sum = 0;

                for ($index = 0; $index < $position; $index++) {
                    $sum += (int) $digits[$index] * (($position + 1) - $index);
                }

                $digit = (10 * $sum) % 11;
                $digit = $digit === 10 ? 0 : $digit;

                if ($digit !== (int) $digits[$position]) {
                    return false;
                }
            }

            return true;
        }

        for ($position = 12; $position < 14; $position++) {
            $sum = 0;
            $weight = $position === 12 ? 5 : 6;

            for ($index = 0; $index < $position; $index++) {
                $sum += (int) $digits[$index] * $weight;
                $weight = $weight === 2 ? 9 : $weight - 1;
            }

            $remainder = $sum % 11;
            $digit = $remainder < 2 ? 0 : 11 - $remainder;

            if ($digit !== (int) $digits[$position]) {
                return false;
            }
        }

        return true;
    }
}
