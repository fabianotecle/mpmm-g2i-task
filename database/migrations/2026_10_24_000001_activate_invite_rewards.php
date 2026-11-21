<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ([
            [
                'name' => 'Convite aceito: R$ 1 para quem convidou',
                'event' => 'invite.sent_accepted',
                'reward_value' => 1,
                'min_event_amount' => null,
            ],
            [
                'name' => 'Convite com carga de R$ 50: R$ 10 para quem convidou',
                'event' => 'invite.accepted_deposit',
                'reward_value' => 10,
                'min_event_amount' => 50,
            ],
        ] as $rule) {
            DB::table('bonus_rules')->insert([
                ...$rule,
                'active' => true,
                'reward_type' => 'fixed',
                'max_awards_per_customer' => null,
                'starts_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('bonus_rules')->whereIn('name', [
            'Convite aceito: R$ 1 para quem convidou',
            'Convite com carga de R$ 50: R$ 10 para quem convidou',
        ])->delete();
    }
};
