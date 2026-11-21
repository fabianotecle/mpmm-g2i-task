<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

class MemberStatistics
{
    public const FINANCIAL_MOVEMENT_SQL = "movements.customer_id = customers.id AND (movements.credit > 0 OR movements.debit > 0) AND movements.event NOT LIKE '%.simulated' AND movements.event <> 'simulation.reversed'";

    public const ACCESS_COUNT_SQL = "(SELECT COUNT(*) FROM conversation_sessions WHERE conversation_sessions.customer_id = customers.id AND EXISTS (SELECT 1 FROM conversation_messages WHERE conversation_messages.conversation_session_id = conversation_sessions.id AND conversation_messages.direction = 'inbound') AND NOT EXISTS (SELECT 1 FROM conversation_messages WHERE conversation_messages.conversation_session_id = conversation_sessions.id AND conversation_messages.direction = 'inbound' AND COALESCE(CAST(JSON_EXTRACT(customers.metadata, '$.access_count') AS DECIMAL(20, 0)), 0) > 0 AND customers.legacy_last_access_at IS NOT NULL AND conversation_messages.created_at <= customers.legacy_last_access_at)) + COALESCE(CAST(JSON_EXTRACT(customers.metadata, '$.access_count') AS DECIMAL(20, 0)), 0)";

    public const MEMBER_ACCESS_SQL = "(EXISTS (SELECT 1 FROM conversation_sessions JOIN conversation_messages ON conversation_messages.conversation_session_id = conversation_sessions.id WHERE conversation_sessions.customer_id = customers.id AND conversation_messages.direction = 'inbound') OR JSON_EXTRACT(customers.metadata, '$.last_legacy_access_at') IS NOT NULL OR COALESCE(CAST(JSON_EXTRACT(customers.metadata, '$.access_count') AS DECIMAL(20, 0)), 0) > 0)";

    public const SITUATION_SQL = "CASE WHEN NOT ".self::MEMBER_ACCESS_SQL." THEN 'no_access' WHEN NOT EXISTS (SELECT 1 FROM movements WHERE ".self::FINANCIAL_MOVEMENT_SQL.") THEN 'no_movements' WHEN customers.balance > 0 THEN 'positive' WHEN customers.balance < 0 THEN 'negative' ELSE 'zero' END";

    public static function situation(float $balance, int $financialMovements, bool $hasAccess): string
    {
        return match (true) {
            ! $hasAccess => 'no_access',
            $financialMovements === 0 => 'no_movements',
            $balance > 0 => 'positive',
            $balance < 0 => 'negative',
            default => 'zero',
        };
    }

    public function summarize(Builder $query): array
    {
        $origins = array_fill_keys(['direct', 'invite', 'network', 'other'], ['count' => 0, 'balance' => 0]);
        foreach ((clone $query)->reorder()->select('acquisition_origin')
            ->selectRaw('COUNT(*) as members_count, COALESCE(SUM(balance), 0) as members_balance')
            ->groupBy('acquisition_origin')->get() as $row) {
            $origin = array_key_exists($row->acquisition_origin ?? '', $origins) ? $row->acquisition_origin : 'other';
            $origins[$origin]['count'] += (int) $row->members_count;
            $origins[$origin]['balance'] = round($origins[$origin]['balance'] + (float) $row->members_balance, 2);
        }

        $situations = array_fill_keys(['no_access', 'no_movements', 'zero', 'positive', 'negative'], ['count' => 0, 'balance' => 0]);
        foreach ((clone $query)->reorder()->select([])
            ->selectRaw(self::SITUATION_SQL.' as situation, COUNT(*) as members_count, COALESCE(SUM(customers.balance), 0) as members_balance')
            ->groupBy('situation')->get() as $row) {
            $situations[$row->situation] = ['count' => (int) $row->members_count, 'balance' => round((float) $row->members_balance, 2)];
        }

        return [
            'count' => array_sum(array_column($origins, 'count')),
            'balance' => round(array_sum(array_column($origins, 'balance')), 2),
            'origins' => $origins,
            'situations' => $situations,
        ];
    }
}
