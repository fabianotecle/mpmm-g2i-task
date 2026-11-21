<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_proposals', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('status');
            $table->index(['status', 'expires_at']);
        });

        DB::table('installment_proposals')
            ->where('status', 'pending')
            ->whereNull('expires_at')
            ->select(['id', 'created_at'])
            ->chunkById(200, function ($proposals): void {
                foreach ($proposals as $proposal) {
                    DB::table('installment_proposals')->where('id', $proposal->id)->update([
                        'expires_at' => Carbon::parse($proposal->created_at, 'UTC')->addMinutes(15),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('installment_proposals', function (Blueprint $table) {
            $table->dropIndex(['status', 'expires_at']);
            $table->dropColumn('expires_at');
        });
    }
};
