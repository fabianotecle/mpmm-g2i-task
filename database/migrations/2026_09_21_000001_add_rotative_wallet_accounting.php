<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'rotative_balance')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->decimal('rotative_balance', 14, 2)->default(0)->after('balance');
            });
        }

        if (! Schema::hasColumn('movements', 'fund_type')) {
            Schema::table('movements', function (Blueprint $table) {
                $table->string('fund_type', 12)->default('debit')->after('event');
                $table->decimal('rotative_debit', 14, 2)->default(0)->after('debit');
                $table->decimal('rotative_credit', 14, 2)->default(0)->after('credit');
                $table->decimal('rotative_balance', 14, 2)->default(0)->after('balance');
                $table->index(['customer_id', 'fund_type', 'created_at']);
            });
        }

        if (! Schema::hasTable('credit_grants')) Schema::create('credit_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('granted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->decimal('available_amount', 14, 2);
            $table->string('status', 20)->default('active');
            $table->string('reason', 500);
            $table->json('rules')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
        });

        if (! Schema::hasTable('credit_debts')) Schema::create('credit_debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_grant_id')->constrained()->restrictOnDelete();
            $table->foreignId('debtor_customer_id')->constrained('customers')->restrictOnDelete();
            $table->decimal('principal', 14, 2);
            $table->decimal('outstanding_amount', 14, 2);
            $table->string('status', 20)->default('open');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->index(['debtor_customer_id', 'status']);
        });

        if (! Schema::hasTable('credit_debt_payments')) Schema::create('credit_debt_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_debt_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index(['credit_debt_id', 'created_at']);
        });

        if (! Schema::hasTable('rotative_lots')) Schema::create('rotative_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_grant_id')->constrained()->restrictOnDelete();
            $table->foreignId('current_customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('credit_debt_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->decimal('remaining_amount', 14, 2);
            $table->dateTime('received_at');
            $table->dateTime('eligible_after');
            $table->string('status', 20)->default('blocked');
            $table->timestamps();
            $table->index(['current_customer_id', 'status', 'eligible_after']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rotative_lots');
        Schema::dropIfExists('credit_debt_payments');
        Schema::dropIfExists('credit_debts');
        Schema::dropIfExists('credit_grants');
        Schema::table('movements', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'fund_type', 'created_at']);
            $table->dropColumn(['fund_type', 'rotative_debit', 'rotative_credit', 'rotative_balance']);
        });
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('rotative_balance'));
    }
};
