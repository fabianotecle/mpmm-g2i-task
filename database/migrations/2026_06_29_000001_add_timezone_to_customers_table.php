<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'timezone')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('timezone', 64)->default('America/Sao_Paulo')->after('phone');
            });
        }

        DB::table('customers')->where('phone', 'like', '351%')->update(['timezone' => 'Europe/Lisbon']);
        DB::table('customers')->where('phone', 'like', '44%')->update(['timezone' => 'Europe/London']);
        DB::table('customers')->where('phone', 'like', '55%')->update(['timezone' => 'America/Sao_Paulo']);
        DB::table('customers')->where('phone', 'like', '5568%')->update(['timezone' => 'America/Rio_Branco']);
        DB::table('customers')->where('phone', 'like', '5569%')->update(['timezone' => 'America/Porto_Velho']);
        DB::table('customers')->where('phone', 'like', '5592%')->update(['timezone' => 'America/Manaus']);
        DB::table('customers')->where('phone', 'like', '5597%')->update(['timezone' => 'America/Manaus']);
        DB::table('customers')->where('phone', 'like', '5595%')->update(['timezone' => 'America/Boa_Vista']);
        DB::table('customers')->where('phone', 'like', '5591%')->update(['timezone' => 'America/Belem']);
        DB::table('customers')->where('phone', 'like', '5593%')->update(['timezone' => 'America/Belem']);
        DB::table('customers')->where('phone', 'like', '5594%')->update(['timezone' => 'America/Belem']);
        DB::table('customers')->where('phone', 'like', '5563%')->update(['timezone' => 'America/Araguaina']);
        DB::table('customers')->where('phone', 'like', '5565%')->update(['timezone' => 'America/Cuiaba']);
        DB::table('customers')->where('phone', 'like', '5566%')->update(['timezone' => 'America/Cuiaba']);
        DB::table('customers')->where('phone', 'like', '5567%')->update(['timezone' => 'America/Campo_Grande']);
        DB::table('customers')->where('phone', 'like', '1%')->update(['timezone' => 'America/New_York']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'timezone')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('timezone');
            });
        }
    }
};
