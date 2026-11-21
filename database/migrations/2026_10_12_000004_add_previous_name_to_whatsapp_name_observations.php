<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_whatsapp_name_observations', function (Blueprint $table) {
            $table->string('previous_profile_name')->nullable()->after('profile_name');
        });

        $previousByCustomer = [];
        foreach (DB::table('customer_whatsapp_name_observations')
            ->orderBy('customer_id')->orderBy('observed_at')->orderBy('id')
            ->get() as $observation) {
            $previous = $previousByCustomer[$observation->customer_id] ?? null;
            if ($observation->name_changed && $previous !== null) {
                DB::table('customer_whatsapp_name_observations')
                    ->where('id', $observation->id)
                    ->update(['previous_profile_name' => $previous]);
            }
            $previousByCustomer[$observation->customer_id] = $observation->profile_name;
        }
    }

    public function down(): void
    {
        Schema::table('customer_whatsapp_name_observations', function (Blueprint $table) {
            $table->dropColumn('previous_profile_name');
        });
    }
};
