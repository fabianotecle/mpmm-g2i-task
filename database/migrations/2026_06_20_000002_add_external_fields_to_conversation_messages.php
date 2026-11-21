<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('conversation_messages', 'external_channel')) {
                $table->string('external_channel')->nullable()->after('payload');
            }

            if (! Schema::hasColumn('conversation_messages', 'external_message_id')) {
                $table->string('external_message_id')->nullable()->after('external_channel');
            }

            if (! Schema::hasColumn('conversation_messages', 'external_timestamp')) {
                $table->timestamp('external_timestamp')->nullable()->after('external_message_id');
            }
        });

        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->unique(['external_channel', 'external_message_id'], 'cm_external_message_unique');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->dropUnique('cm_external_message_unique');
            $table->dropColumn([
                'external_channel',
                'external_message_id',
                'external_timestamp',
            ]);
        });
    }
};
