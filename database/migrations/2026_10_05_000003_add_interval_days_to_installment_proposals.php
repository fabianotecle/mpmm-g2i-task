<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_proposals', function (Blueprint $table) {
            // Proposals accepted before the daily test retain their 30-day schedule.
            $table->unsignedTinyInteger('interval_days')->default(30);
        });
    }

    public function down(): void
    {
        Schema::table('installment_proposals', function (Blueprint $table) {
            $table->dropColumn('interval_days');
        });
    }
};
