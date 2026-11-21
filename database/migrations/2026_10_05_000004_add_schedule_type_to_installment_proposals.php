<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_proposals', function (Blueprint $table) {
            // Existing proposals keep their already agreed fixed-day interval.
            $table->string('schedule_type', 20)->default('fixed_days');
            $table->string('schedule_timezone', 64)->default('UTC');
        });
    }

    public function down(): void
    {
        Schema::table('installment_proposals', function (Blueprint $table) {
            $table->dropColumn(['schedule_type', 'schedule_timezone']);
        });
    }
};
