<?php

namespace Tests\Unit;

use App\Services\Phone\PhoneNumberNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberNormalizerTest extends TestCase
{
    #[DataProvider('phoneFormats')]
    public function test_it_normalizes_supported_local_and_international_numbers(
        string $input,
        string $reference,
        string $expected,
    ): void {
        $this->assertSame($expected, (new PhoneNumberNormalizer)->normalize($input, $reference));
    }

    public static function phoneFormats(): array
    {
        return [
            'Sao Paulo mobile is Brazilian in a Brazilian conversation' => ['11900000001', '5511900000001', '5511900000001'],
            'Sao Paulo formatted mobile' => ['(11) 90000-0002', '5551900000009', '5511900000002'],
            'Explicit international prefix is preserved' => ['+12125551234', '5551900000009', '12125551234'],
            'Brazil without DDI and DDD' => ['90000-0009', '5551999990010', '5551900000009'],
            'Brazil old mobile without DDI and DDD' => ['0000-0009', '5551999990010', '5551900000009'],
            'Brazil with DDD' => ['5190000-0009', '5551999990010', '5551900000009'],
            'Brazil old mobile with DDI' => ['555100000009', '5551999990010', '5551900000009'],
            'Brazil old mobile with DDD' => ['5100000009', '5551999990010', '5551900000009'],
            'Portugal local' => ['912345678', '351911111111', '351912345678'],
            'United States local' => ['5551234', '12125550000', '12125551234'],
            'United Kingdom national' => ['020 7946 0958', '447700900123', '442079460958'],
        ];
    }

    public function test_it_rejects_unknown_brazilian_area_codes(): void
    {
        $this->assertNull((new PhoneNumberNormalizer)->normalize('20900000003', '5551999990010'));
        $this->assertNull((new PhoneNumberNormalizer)->normalize('5520900000003', '5551999990010'));
    }
}
