<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inviter_customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('recipient_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('invite_code', 32)->index();
            $table->string('sent_to_phone')->nullable()->index();
            $table->string('sent_to_name')->nullable();
            $table->string('received_by_phone')->nullable()->index();
            $table->string('received_by_name')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['invite_code', 'received_by_phone']);
            $table->index(['inviter_customer_id', 'recipient_customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_invites');
    }
};
