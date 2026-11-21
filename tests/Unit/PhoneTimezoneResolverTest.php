<?php

namespace Tests\Unit;

use App\Services\Time\PhoneTimezoneResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneTimezoneResolverTest extends TestCase
{
    #[DataProvider('phones')]
    public function test_it_resolves_timezone_from_country_and_area_code(string $phone, string $expected): void
    {
        $this->assertSame($expected, (new PhoneTimezoneResolver)->resolve($phone));
    }

    public static function phones(): array
    {
        return [
            'Portugal' => ['351912345678', 'Europe/Lisbon'],
            'United Kingdom' => ['447700900123', 'Europe/London'],
            'Brazil Porto Alegre' => ['5551900000009', 'America/Sao_Paulo'],
            'Brazil Manaus' => ['5592999999999', 'America/Manaus'],
            'Brazil Acre' => ['5568999999999', 'America/Rio_Branco'],
            'United States' => ['12125550000', 'America/New_York'],
            'Florida 407' => ['14072121571', 'America/New_York'],
            'Florida 689 overlay' => ['16898676703', 'America/New_York'],
            'Canada Toronto' => ['16475026369', 'America/Toronto'],
            'California' => ['14155552671', 'America/Los_Angeles'],
            'Arizona without summer shift' => ['16025552671', 'America/Phoenix'],
            'Hawaii' => ['18085552671', 'Pacific/Honolulu'],
            'Argentina' => ['5491123456789', 'America/Argentina/Buenos_Aires'],
            'Germany' => ['4915123456789', 'Europe/Berlin'],
            'Uruguay' => ['59899123456', 'America/Montevideo'],
            'Ambiguous North American prefix' => ['16055552671', 'UTC'],
            'Unknown' => ['999999999999', 'UTC'],
        ];
    }

    public function test_the_same_utc_transfer_has_each_members_local_time_in_both_seasons(): void
    {
        $resolver = new PhoneTimezoneResolver;
        $brazil = $resolver->resolve('5551900000009');
        $portugal = $resolver->resolve('351912345678');
        $newYork = $resolver->resolve('14072121571');

        $winter = new \DateTimeImmutable('2026-01-15 15:00:00', new \DateTimeZone('UTC'));
        $summer = new \DateTimeImmutable('2026-07-15 15:00:00', new \DateTimeZone('UTC'));

        $this->assertSame('12:00 -03:00', $winter->setTimezone(new \DateTimeZone($brazil))->format('H:i P'));
        $this->assertSame('15:00 +00:00', $winter->setTimezone(new \DateTimeZone($portugal))->format('H:i P'));
        $this->assertSame('16:00 +01:00', $summer->setTimezone(new \DateTimeZone($portugal))->format('H:i P'));
        $this->assertSame('10:00 -05:00', $winter->setTimezone(new \DateTimeZone($newYork))->format('H:i P'));
        $this->assertSame('11:00 -04:00', $summer->setTimezone(new \DateTimeZone($newYork))->format('H:i P'));
    }
}
