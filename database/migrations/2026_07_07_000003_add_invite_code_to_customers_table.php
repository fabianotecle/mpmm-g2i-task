<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'invite_code')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('invite_code', 32)->nullable()->unique()->after('whatsapp_profile_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'invite_code')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropUnique(['invite_code']);
                $table->dropColumn('invite_code');
            });
        }
    }
};
