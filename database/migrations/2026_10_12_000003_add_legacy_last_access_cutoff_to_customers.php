<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dateTime('legacy_last_access_at')->nullable();
        });

        DB::table('customers')->whereNotNull('metadata')->select(['id', 'metadata'])->orderBy('id')
            ->chunkById(200, function ($customers) {
                foreach ($customers as $customer) {
                    $metadata = json_decode($customer->metadata, true);
                    $lastAccess = $metadata['last_legacy_access_at'] ?? null;
                    if (! is_string($lastAccess) || trim($lastAccess) === '') {
                        continue;
                    }

                    DB::table('customers')->where('id', $customer->id)->update([
                        'legacy_last_access_at' => CarbonImmutable::parse($lastAccess)->utc()->format('Y-m-d H:i:s'),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('legacy_last_access_at'));
    }
};
