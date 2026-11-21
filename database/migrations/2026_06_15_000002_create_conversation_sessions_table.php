<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('channel')->default('api');
            $table->string('external_user_id');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('current_flow')->nullable();
            $table->string('current_step')->nullable();
            $table->string('status')->default('active');
            $table->json('context')->nullable();
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamps();

            $table->index(['channel', 'external_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_sessions');
    }
};
