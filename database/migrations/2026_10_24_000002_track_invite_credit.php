<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('bonus_credit_balance', 14, 2)->default(0);
        });

        Schema::table('movements', function (Blueprint $table) {
            $table->decimal('bonus_credit_debit', 14, 2)->default(0);
            $table->decimal('bonus_credit_credit', 14, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            $table->dropColumn(['bonus_credit_debit', 'bonus_credit_credit']);
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('bonus_credit_balance');
        });
    }
};
