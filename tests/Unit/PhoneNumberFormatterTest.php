<?php

namespace Tests\Unit;

use App\Services\Phone\PhoneNumberFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberFormatterTest extends TestCase
{
    #[DataProvider('internationalFormats')]
    public function test_it_formats_supported_international_numbers(string $phone, string $expected): void
    {
        $this->assertSame($expected, (new PhoneNumberFormatter)->international($phone));
    }

    public static function internationalFormats(): array
    {
        return [
            'Brazil mobile' => ['5551900000009', '+55 (51) 90000-0009'],
            'Brazil old mobile' => ['555100000009', '+55 (51) 0000-0009'],
            'Portugal' => ['351912345678', '+351 912 345 678'],
            'United States' => ['12125551234', '+1 (212) 555-1234'],
            'United Kingdom' => ['442079460958', '+44 2079 460 958'],
        ];
    }

    #[DataProvider('privateMasks')]
    public function test_it_masks_private_numbers_by_country_shape(string $phone, string $expected): void
    {
        $this->assertSame($expected, (new PhoneNumberFormatter)->privateMask($phone));
    }

    public static function privateMasks(): array
    {
        return [
            'Brazil' => ['5551999990010', '+55 (51) *****-**10'],
            'Portugal' => ['351912345678', '+351 9** *** **78'],
            'NANP' => ['12125551234', '+1 (212) ***-**34'],
            'UK' => ['442079460958', '+44 20** *** **58'],
            'Fallback' => ['4989123456789', '4989*******89'],
        ];
    }
}
