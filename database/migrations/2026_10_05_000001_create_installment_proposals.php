<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('recipient_customer_id')->constrained('customers')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->unsignedTinyInteger('installments');
            $table->string('status', 20)->default('pending');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['recipient_customer_id', 'status']);
            $table->index(['sender_customer_id', 'status']);
        });

        Schema::create('installment_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installment_proposal_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('number');
            $table->decimal('amount', 14, 2);
            $table->timestamp('due_at');
            $table->string('status', 20)->default('pending');
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('sent_movement_id')->nullable()->constrained('movements')->nullOnDelete();
            $table->foreignId('received_movement_id')->nullable()->constrained('movements')->nullOnDelete();
            $table->timestamps();
            $table->unique(['installment_proposal_id', 'number']);
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_payments');
        Schema::dropIfExists('installment_proposals');
    }
};
