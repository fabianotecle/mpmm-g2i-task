<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Original MpMM registration dates for customers matched by phone during import.
        // All other imported customers already retained their original dates.
        foreach ([
            '7shAKIkBkUcxk9YnLYCH' => '2023-07-05 22:50:41',
            '2MhEDokBkUcxk9Ynl3-E' => '2023-06-30 21:45:23',
            'e44b345e-af90-4450-b0ae-207d55b9748d' => '2026-06-21 02:16:46',
            '69872b70-97e0-43ba-95ec-e09dffcc1fbc' => '2026-02-24 16:55:11',
            'e5dbbab7-1a7d-4d52-ae1f-bb260a981584' => '2025-06-19 20:20:53',
            'f5c76132-f59e-41dc-98c0-e6659eee0a25' => '2024-07-05 03:29:27',
            'd0281985-be21-4639-a2f0-e04bdf7201ef' => '2025-11-19 22:34:02',
        ] as $legacyId => $registeredAt) {
            DB::table('customers')
                ->where('metadata->legacy_customer_id', $legacyId)
                ->update(['created_at' => $registeredAt]);
        }
    }

    public function down(): void
    {
        // The original timestamps are authoritative; do not restore incorrect dates.
    }
};
