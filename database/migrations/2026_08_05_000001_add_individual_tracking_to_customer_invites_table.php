<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invites', function (Blueprint $table) {
            $table->string('individual_code', 32)->nullable()->unique()->after('invite_code');
            $table->string('status', 20)->default('created')->index()->after('individual_code');
            $table->timestamp('expires_at')->nullable()->after('accepted_at');
            $table->timestamp('revoked_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_invites', function (Blueprint $table) {
            $table->dropUnique(['individual_code']);
            $table->dropColumn(['individual_code', 'status', 'expires_at', 'revoked_at']);
        });
    }
};
