<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_ordered_menus', function (Blueprint $table) {
            $table->id();
            $table->string('primary_message_id')->unique();
            $table->string('recipient_phone');
            $table->json('secondary_payload');
            $table->foreignId('conversation_message_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('delivery_index');
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_ordered_menus');
    }
};
