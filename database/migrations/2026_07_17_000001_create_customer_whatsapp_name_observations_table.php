<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_whatsapp_name_observations')) {
            return;
        }

        Schema::create('customer_whatsapp_name_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_message_id')->unique();
            $table->string('profile_name');
            $table->boolean('name_changed')->default(false);
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->index(['customer_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_whatsapp_name_observations');
    }
};
