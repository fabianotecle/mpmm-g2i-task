<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_payment_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('legacy_id', 100);
            $table->string('provider', 50)->nullable();
            $table->string('status', 50)->nullable();
            $table->decimal('amount', 14, 2);
            $table->decimal('fee', 14, 2)->default(0);
            $table->unsignedBigInteger('nsu')->nullable();
            $table->string('external_operation_id')->nullable();
            $table->text('external_receipt_url')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'legacy_id']);
            $table->index(['customer_id', 'kind']);
            $table->index(['provider', 'external_operation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_payment_operations');
    }
};
