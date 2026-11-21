<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('transfer_id')->nullable()->index();
            $table->string('recipient_phone', 32)->index();
            $table->json('notification');
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['transfer_id', 'recipient_phone'], 'wa_delivery_transfer_recipient_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_notification_deliveries');
    }
};
