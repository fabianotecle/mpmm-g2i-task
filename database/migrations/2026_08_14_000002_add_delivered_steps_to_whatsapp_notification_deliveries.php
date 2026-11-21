<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_notification_deliveries', function (Blueprint $table) {
            $table->json('delivered_steps')->nullable()->after('notification');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_notification_deliveries', function (Blueprint $table) {
            $table->dropColumn('delivered_steps');
        });
    }
};
