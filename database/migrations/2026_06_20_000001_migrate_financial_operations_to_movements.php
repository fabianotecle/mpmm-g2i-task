<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('financial_operations') && ! Schema::hasTable('movements')) {
            Schema::rename('financial_operations', 'movements');
        }

        if (! Schema::hasTable('movements')) {
            return;
        }

        $this->dropLegacyIndex();

        Schema::table('movements', function (Blueprint $table) {
            if (! Schema::hasColumn('movements', 'origin_customer_id')) {
                $table->foreignId('origin_customer_id')->nullable()->after('customer_id')->constrained('customers')->nullOnDelete();
            }

            if (! Schema::hasColumn('movements', 'debit')) {
                $table->decimal('debit', 14, 2)->default(0)->after('conversation_session_id');
            }

            if (! Schema::hasColumn('movements', 'credit')) {
                $table->decimal('credit', 14, 2)->default(0)->after('debit');
            }

            if (! Schema::hasColumn('movements', 'balance')) {
                $table->decimal('balance', 14, 2)->default(0)->after('credit');
            }

            if (! Schema::hasColumn('movements', 'event')) {
                $table->string('event')->default('legacy.imported')->after('balance');
            }

            if (! Schema::hasColumn('movements', 'external_provider')) {
                $table->string('external_provider')->nullable()->after('event');
            }

            if (! Schema::hasColumn('movements', 'external_operation_id')) {
                $table->string('external_operation_id')->nullable()->after('external_provider');
            }

            if (! Schema::hasColumn('movements', 'external_receipt_url')) {
                $table->string('external_receipt_url')->nullable()->after('external_operation_id');
            }

            if (! Schema::hasColumn('movements', 'metadata')) {
                $table->json('metadata')->nullable()->after('external_receipt_url');
            }
        });

        if (Schema::hasColumn('movements', 'amount')) {
            DB::table('movements')->update([
                'credit' => DB::raw('COALESCE(amount, 0)'),
                'balance' => DB::raw('COALESCE(amount, 0)'),
            ]);
        }

        Schema::table('movements', function (Blueprint $table) {
            $legacyColumns = array_values(array_filter([
                Schema::hasColumn('movements', 'type') ? 'type' : null,
                Schema::hasColumn('movements', 'status') ? 'status' : null,
                Schema::hasColumn('movements', 'amount') ? 'amount' : null,
                Schema::hasColumn('movements', 'payload') ? 'payload' : null,
            ]));

            if ($legacyColumns !== []) {
                $table->dropColumn($legacyColumns);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('movements')) {
            return;
        }

        Schema::table('movements', function (Blueprint $table) {
            if (! Schema::hasColumn('movements', 'type')) {
                $table->string('type')->default('legacy')->after('conversation_session_id');
            }

            if (! Schema::hasColumn('movements', 'status')) {
                $table->string('status')->default('simulated')->after('type');
            }

            if (! Schema::hasColumn('movements', 'amount')) {
                $table->decimal('amount', 14, 2)->nullable()->after('status');
            }

            if (! Schema::hasColumn('movements', 'payload')) {
                $table->json('payload')->nullable()->after('amount');
            }
        });

        DB::table('movements')->update([
            'amount' => DB::raw('credit + debit'),
        ]);

        if (! Schema::hasTable('financial_operations')) {
            Schema::rename('movements', 'financial_operations');
        }
    }

    private function dropLegacyIndex(): void
    {
        try {
            Schema::table('movements', function (Blueprint $table) {
                $table->dropIndex('financial_operations_type_status_index');
            });
        } catch (Throwable) {
            // Fresh installs create movements directly and never have the legacy index.
        }
    }
};
