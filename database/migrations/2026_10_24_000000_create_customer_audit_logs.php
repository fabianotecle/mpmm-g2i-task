<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('customer_phone', 30)->index();
            $table->string('previous_phone', 30)->nullable()->index();
            $table->string('action', 16);
            $table->string('source', 40);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('actor_customer_id')->nullable();
            $table->string('actor_phone', 30)->nullable();
            $table->unsignedBigInteger('conversation_session_id')->nullable();
            $table->string('external_message_id')->nullable();
            $table->uuid('request_id')->nullable()->index();
            $table->string('database_account')->nullable();
            $table->unsignedBigInteger('database_connection_id')->nullable();
            $table->text('changed_fields')->nullable();
            $table->json('identity_before')->nullable();
            $table->json('identity_after')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['customer_phone', 'created_at']);
            $table->index(['conversation_session_id', 'created_at']);
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $before = $this->identity('OLD');
        $after = $this->identity('NEW');
        $context = "COALESCE(NULLIF(@mpmm_audit_source, ''), 'database'),
            @mpmm_audit_actor_user_id, @mpmm_audit_actor_customer_id,
            @mpmm_audit_actor_phone, @mpmm_audit_session_id,
            @mpmm_audit_message_id, @mpmm_audit_request_id";
        $columns = 'customer_id, customer_phone, previous_phone, action, source, actor_user_id,
            actor_customer_id, actor_phone, conversation_session_id,
            external_message_id, request_id, database_account,
            database_connection_id, changed_fields,
            identity_before, identity_after';

        try {
            DB::unprepared("CREATE TRIGGER customers_audit_insert AFTER INSERT ON customers
            FOR EACH ROW BEGIN
                INSERT INTO customer_audit_logs ($columns)
                VALUES (NEW.id, NEW.phone, NULL, 'created', $context,
                    USER(), CONNECTION_ID(), 'created', NULL, $after);
            END");

            $tracked = array_values(array_diff(Schema::getColumnListing('customers'), ['id', 'updated_at']));
            $changed = implode(",\n", array_map(
                fn (string $column) => "IF(NOT (OLD.`$column` <=> NEW.`$column`), '$column', NULL)",
                $tracked,
            ));
            DB::unprepared("CREATE TRIGGER customers_audit_update AFTER UPDATE ON customers
            FOR EACH ROW BEGIN
                DECLARE changed_fields TEXT;
                SET changed_fields = CONCAT_WS(',', $changed);
                IF changed_fields <> '' THEN
                    INSERT INTO customer_audit_logs ($columns)
                    VALUES (NEW.id, NEW.phone, OLD.phone, 'updated', $context,
                        USER(), CONNECTION_ID(), changed_fields, $before, $after);
                END IF;
            END");

            DB::unprepared("CREATE TRIGGER customers_audit_delete AFTER DELETE ON customers
            FOR EACH ROW BEGIN
                INSERT INTO customer_audit_logs ($columns)
                VALUES (OLD.id, OLD.phone, OLD.phone, 'deleted', $context,
                    USER(), CONNECTION_ID(), 'deleted', $before, NULL);
            END");
        } catch (Throwable $error) {
            foreach (['insert', 'update', 'delete'] as $action) {
                DB::unprepared("DROP TRIGGER IF EXISTS customers_audit_$action");
            }
            Schema::dropIfExists('customer_audit_logs');

            throw $error;
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (['insert', 'update', 'delete'] as $action) {
                DB::unprepared("DROP TRIGGER IF EXISTS customers_audit_$action");
            }
        }

        Schema::dropIfExists('customer_audit_logs');
    }

    private function identity(string $row): string
    {
        return "JSON_OBJECT('phone', $row.phone, 'name', $row.name,
            'whatsapp_id', $row.whatsapp_id,
            'whatsapp_username', $row.whatsapp_username,
            'whatsapp_profile_name', $row.whatsapp_profile_name)";
    }
};
