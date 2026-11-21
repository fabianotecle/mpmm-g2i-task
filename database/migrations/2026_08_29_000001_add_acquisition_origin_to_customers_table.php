<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'acquisition_origin')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('acquisition_origin', 20)->default('direct')->after('status')->index();
            });
        }

        $invitedCustomerIds = array_fill_keys(
            DB::table('customer_invites')->whereNotNull('recipient_customer_id')->pluck('recipient_customer_id')->all(),
            true,
        );
        $networkCustomerIds = array_fill_keys(
            DB::table('movements')
                ->where('event', 'transfer.received')
                ->whereNotNull('origin_customer_id')
                ->where('credit', '>', 0)
                ->pluck('customer_id')
                ->unique()
                ->all(),
            true,
        );

        DB::table('customers')->select(['id', 'metadata'])->orderBy('id')->each(function ($customer) use ($invitedCustomerIds, $networkCustomerIds) {
            $metadata = json_decode((string) $customer->metadata, true) ?: [];
            $legacyOrigin = strtolower(trim((string) ($metadata['legacy_origin'] ?? '')));

            $origin = match (true) {
                isset($invitedCustomerIds[$customer->id]),
                ! empty($metadata['invited_by_legacy_id']),
                $legacyOrigin === 'invited' => 'invite',
                $legacyOrigin === 'net' => 'network',
                $legacyOrigin === 'itself' => 'direct',
                $legacyOrigin !== '' => 'other',
                isset($networkCustomerIds[$customer->id]) => 'network',
                default => 'direct',
            };

            DB::table('customers')->where('id', $customer->id)->update(['acquisition_origin' => $origin]);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['acquisition_origin']);
            $table->dropColumn('acquisition_origin');
        });
    }
};
