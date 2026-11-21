<?php

namespace App\Services\Phone;

class PhoneNumberFormatter
{
    public function international(string $phone): string
    {
        $digits = $this->digits($phone);

        if ($digits === '') {
            return $phone;
        }

        if ($this->isBrazil($digits)) {
            return '+55 ('.substr($digits, 2, 2).') '.$this->formatBrazilSubscriber(substr($digits, 4));
        }

        if ($this->isPortugal($digits)) {
            return '+351 '.substr($digits, 3, 3).' '.substr($digits, 6, 3).' '.substr($digits, 9);
        }

        if ($this->isNanp($digits)) {
            return '+1 ('.substr($digits, 1, 3).') '.substr($digits, 4, 3).'-'.substr($digits, 7);
        }

        if ($this->isUnitedKingdom($digits)) {
            return '+44 '.substr($digits, 2, 4).' '.substr($digits, 6, 3).' '.substr($digits, 9);
        }

        return '+'.$digits;
    }

    public function brazilianPixKey(string $phone): string
    {
        $digits = $this->digits($phone);

        if ($this->isBrazil($digits)) {
            return '('.substr($digits, 2, 2).') '.$this->formatBrazilSubscriber(substr($digits, 4));
        }

        return $this->international($phone);
    }

    public function admin(string $phone): string
    {
        $digits = $this->digits($phone);

        if ($this->isBrazil($digits)) {
            return '55 '.substr($digits, 2, 2).' '.$this->formatBrazilSubscriber(substr($digits, 4));
        }

        return $this->international($phone);
    }

    public function privateMask(string $phone): string
    {
        $digits = $this->digits($phone);

        if ($digits === '') {
            return '';
        }

        if ($this->isBrazil($digits)) {
            $lastTwo = substr($digits, -2);

            return '+55 ('.substr($digits, 2, 2).') *****-**'.$lastTwo;
        }

        if ($this->isPortugal($digits)) {
            return '+351 '.substr($digits, 3, 1).'** *** **'.substr($digits, -2);
        }

        if ($this->isNanp($digits)) {
            return '+1 ('.substr($digits, 1, 3).') ***-**'.substr($digits, -2);
        }

        if ($this->isUnitedKingdom($digits)) {
            return '+44 '.substr($digits, 2, 2).'** *** **'.substr($digits, -2);
        }

        if (strlen($digits) <= 6) {
            return str_repeat('*', strlen($digits));
        }

        return substr($digits, 0, 4).str_repeat('*', strlen($digits) - 6).substr($digits, -2);
    }

    private function isBrazil(string $digits): bool
    {
        return str_starts_with($digits, '55') && in_array(strlen($digits), [12, 13], true);
    }

    private function isPortugal(string $digits): bool
    {
        return str_starts_with($digits, '351') && strlen($digits) === 12;
    }

    private function isNanp(string $digits): bool
    {
        return str_starts_with($digits, '1') && strlen($digits) === 11;
    }

    private function isUnitedKingdom(string $digits): bool
    {
        return str_starts_with($digits, '44') && strlen($digits) === 12;
    }

    private function formatBrazilSubscriber(string $subscriber): string
    {
        if (strlen($subscriber) === 9) {
            return substr($subscriber, 0, 5).'-'.substr($subscriber, 5);
        }

        return substr($subscriber, 0, 4).'-'.substr($subscriber, 4);
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?: '';
    }
}
