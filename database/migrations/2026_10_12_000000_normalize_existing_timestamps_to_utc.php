<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        // Before this release MySQL used the server's SYSTEM timezone while
        // Laravel supplied UTC clock strings. TIMESTAMP therefore stored a
        // shifted instant. Read the former session offset before correcting it.
        try {
            DB::statement("SET time_zone = 'SYSTEM'");
            $offset = (int) DB::selectOne('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS seconds')->seconds;
        } finally {
            DB::statement("SET time_zone = '+00:00'");
        }

        if ($offset === 0) {
            return;
        }

        // All MPMM records are from 2023 onward, when this server has used UTC-3.
        // Stop instead of guessing if the target has a different SQL clock.
        if ($offset !== -10800) {
            throw new \RuntimeException("Unexpected legacy MySQL timezone offset: {$offset} seconds.");
        }

        foreach (['customers', 'movements'] as $table) {
            $earliest = DB::table($table)->min('created_at');
            if ($earliest && $earliest < '2019-01-01 00:00:00') {
                throw new \RuntimeException("Historical {$table} timestamps require a DST-aware audit before UTC conversion.");
            }
        }

        $columns = DB::select(<<<'SQL'
            SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND DATA_TYPE = 'timestamp'
              AND TABLE_NAME IN (
                'bonus_rules', 'conversation_messages', 'conversation_sessions',
                'credit_debts', 'credit_debt_payments', 'credit_grants',
                'customers', 'customer_invites', 'customer_whatsapp_name_observations',
                'general_contents', 'installment_payments', 'installment_proposals',
                'legacy_payment_operations', 'movements', 'password_reset_tokens',
                'payment_qr_codes', 'personal_access_tokens', 'rotative_lots',
                'users', 'whatsapp_notification_deliveries'
              )
            ORDER BY TABLE_NAME, ORDINAL_POSITION
            SQL);

        $byTable = [];
        foreach ($columns as $column) {
            $byTable[$column->table_name][] = $column->column_name;
        }

        DB::transaction(function () use ($byTable, $offset): void {
            foreach ($byTable as $table => $names) {
                $assignments = array_map(
                    fn (string $column) => "`{$column}` = DATE_ADD(`{$column}`, INTERVAL {$offset} SECOND)",
                    $names,
                );
                DB::statement("UPDATE `{$table}` SET ".implode(', ', $assignments));
            }
        });
    }

    public function down(): void
    {
        // New UTC writes can coexist with corrected history; reversing is unsafe.
    }
};
