<?php

use App\Services\Time\PhoneTimezoneResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $resolver = app(PhoneTimezoneResolver::class);

        DB::table('customers')->select(['id', 'phone', 'timezone', 'metadata'])
            ->orderBy('id')->chunkById(200, function ($customers) use ($resolver): void {
                foreach ($customers as $customer) {
                    $metadata = json_decode((string) $customer->metadata, true) ?: [];
                    $legacyOffset = preg_match('/^[+-]\d{2}:\d{2}$/', (string) $customer->timezone);
                    $oldNorthAmericaDefault = $customer->timezone === 'America/New_York'
                        && str_starts_with((string) $customer->phone, '1')
                        && ($metadata['timezone_source'] ?? null) !== 'manual';
                    if (! $legacyOffset && ! $oldNorthAmericaDefault) {
                        continue;
                    }
                    $timezone = $resolver->resolve((string) $customer->phone);
                    if (($timezone !== 'UTC' || $oldNorthAmericaDefault) && $timezone !== $customer->timezone) {
                        DB::table('customers')->where('id', $customer->id)
                            ->update([
                                'timezone' => $timezone,
                                'metadata' => json_encode([...$metadata, 'timezone_source' => 'phone'], JSON_THROW_ON_ERROR),
                            ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // The previous fixed offsets cannot be reconstructed from an IANA zone.
    }
};
