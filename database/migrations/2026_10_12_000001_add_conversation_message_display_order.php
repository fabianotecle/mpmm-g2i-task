<?php

use App\Services\Conversation\ConversationMessageOrderRepair;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('display_order')->nullable()->index();
        });

        app(ConversationMessageOrderRepair::class)->repair();
    }

    public function down(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->dropColumn('display_order');
        });
    }
};
