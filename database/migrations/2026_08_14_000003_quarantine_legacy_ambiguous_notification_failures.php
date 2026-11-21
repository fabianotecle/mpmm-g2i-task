<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('whatsapp_notification_deliveries')
            ->where('status', 'failed')
            ->whereNull('delivered_steps')
            ->where('attempts', '>', 0)
            ->update([
                'status' => 'manual_review',
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('whatsapp_notification_deliveries')
            ->where('status', 'manual_review')
            ->whereNull('delivered_steps')
            ->update([
                'status' => 'failed',
                'updated_at' => now(),
            ]);
    }
};
