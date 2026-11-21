<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bonus_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('event')->index();
            $table->boolean('active')->default(false)->index();
            $table->string('reward_type', 16)->default('fixed');
            $table->decimal('reward_value', 14, 4)->default(0);
            $table->decimal('min_event_amount', 14, 2)->nullable();
            $table->unsignedInteger('max_awards_per_customer')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['active', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_rules');
    }
};
