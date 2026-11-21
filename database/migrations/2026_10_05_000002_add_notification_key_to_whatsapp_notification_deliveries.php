<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_notification_deliveries', function (Blueprint $table) {
            $table->string('notification_key', 100)->nullable();
            $table->unique(['notification_key', 'recipient_phone'], 'wa_delivery_notification_recipient_unique');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_notification_deliveries', function (Blueprint $table) {
            $table->dropUnique('wa_delivery_notification_recipient_unique');
            $table->dropColumn('notification_key');
        });
    }
};
