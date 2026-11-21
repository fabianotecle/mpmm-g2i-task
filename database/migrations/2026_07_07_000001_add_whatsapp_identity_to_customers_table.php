<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'whatsapp_id')) {
                $table->string('whatsapp_id')->nullable()->unique()->after('phone');
            }

            if (! Schema::hasColumn('customers', 'whatsapp_username')) {
                $table->string('whatsapp_username')->nullable()->unique()->after('whatsapp_id');
            }

            if (! Schema::hasColumn('customers', 'whatsapp_profile_name')) {
                $table->string('whatsapp_profile_name')->nullable()->after('whatsapp_username');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'whatsapp_id')) {
                $table->dropUnique(['whatsapp_id']);
            }

            if (Schema::hasColumn('customers', 'whatsapp_username')) {
                $table->dropUnique(['whatsapp_username']);
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('customers', 'whatsapp_id') ? 'whatsapp_id' : null,
                Schema::hasColumn('customers', 'whatsapp_username') ? 'whatsapp_username' : null,
                Schema::hasColumn('customers', 'whatsapp_profile_name') ? 'whatsapp_profile_name' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
