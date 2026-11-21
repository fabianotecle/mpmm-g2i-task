<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('financial_operations')) {
            return;
        }

        Schema::create('movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('origin_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('conversation_session_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->decimal('balance', 14, 2)->default(0);
            $table->string('event');
            $table->string('external_provider')->nullable();
            $table->string('external_operation_id')->nullable();
            $table->string('external_receipt_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index(['origin_customer_id']);
            $table->index(['event']);
            $table->index(['external_provider', 'external_operation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movements');
    }
};
