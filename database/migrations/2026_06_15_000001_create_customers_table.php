<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->unique();
            $table->string('cpf', 14)->nullable()->unique();
            $table->string('name')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('status')->default('active');
            $table->decimal('balance', 14, 2)->default(0);
            $table->decimal('redeemable_balance', 14, 2)->default(0);
            $table->decimal('limit', 14, 2)->default(0);
            $table->boolean('opt_in')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
