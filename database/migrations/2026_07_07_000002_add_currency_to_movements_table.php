<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            if (! Schema::hasColumn('movements', 'currency')) {
                $table->string('currency', 3)->default('BRL')->after('balance');
            }
        });

        DB::table('movements')
            ->whereNull('currency')
            ->update(['currency' => 'BRL']);
    }

    public function down(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            if (Schema::hasColumn('movements', 'currency')) {
                $table->dropColumn('currency');
            }
        });
    }
};
