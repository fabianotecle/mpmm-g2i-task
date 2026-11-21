<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\CreditDebt;
use App\Models\CreditGrant;
use App\Models\Movement;
use App\Models\User;
use App\Services\CashOut\CashOutAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashOutAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_calculates_cash_out_without_using_available_credit(): void
    {
        $customer = Customer::create([
            'phone' => '5551999990900',
            'balance' => -20,
            'limit' => 100,
            'metadata' => ['limit_auto' => false],
        ]);

        $this->movement($customer, 'deposit.confirmed', credit: 200);
        $this->movement($customer, 'redeem_pix.submitted', debit: 40);
        $this->movement($customer, 'cash_out.fee', debit: 5);
        $this->movement($customer, 'transfer.received', credit: 70);
        $this->movement($customer, 'transfer.sent', debit: 205);

        $summary = app(CashOutAvailability::class)->summary($customer);

        $this->assertSame(160.0, $summary['net_deposits']);
        $this->assertSame(155.0, $summary['security_cash_out']);
        $this->assertSame(20.0, $summary['normal_cash_out']);
        $this->assertSame(20.0, $summary['credit_used']);
        $this->assertSame(80.0, $summary['credit_available']);
        $this->assertSame(0.0, $summary['wallet_capacity']);
        $this->assertSame(0.0, $summary['extended_cash_out']);
        $this->assertSame(0.0, $summary['available']);
    }

    public function test_it_reports_negative_net_deposits_and_does_not_make_credit_redeemable(): void
    {
        $customer = Customer::create([
            'phone' => '5551999990902',
            'balance' => 29711.10,
            'limit' => 150000,
        ]);

        $this->movement($customer, 'redeem_pix.submitted', debit: 10);
        $this->movement($customer, 'transfer.received', credit: 31221.10);
        $this->movement($customer, 'transfer.sent', debit: 1500);

        $summary = app(CashOutAvailability::class)->summary($customer);

        $this->assertSame(-10.0, $summary['net_deposits']);
        $this->assertSame(31221.10, $summary['transfers_received']);
        $this->assertSame(1500.0, $summary['transfers_sent']);
        $this->assertSame(29711.10, $summary['normal_cash_out']);
        $this->assertSame(29711.10, $summary['extended_cash_out']);
        $this->assertSame(29711.10, $summary['available']);
    }

    public function test_it_deducts_fixed_and_percentage_fees_from_maximum_amount(): void
    {
        config([
            'mpmm.cash_out.fixed_fee' => 5,
            'mpmm.cash_out.percentage_rate' => 3,
        ]);
        $customer = Customer::create([
            'phone' => '5551900000012',
            'balance' => 108,
        ]);
        $this->movement($customer, 'deposit.confirmed', credit: 108);

        $cashOut = app(CashOutAvailability::class);

        $this->assertSame(100.0, $cashOut->availableFor($customer));
        $this->assertSame(8.0, $cashOut->feeFor(100));
    }

    public function test_it_excludes_every_rotative_credit_and_reserves_open_credit_debts(): void
    {
        $customer = Customer::create(['phone' => '5551999990903', 'balance' => 100, 'rotative_balance' => 70]);
        $this->movement($customer, 'deposit.confirmed', credit: 100);
        Movement::create([
            'customer_id' => $customer->id,
            'credit' => 0,
            'rotative_credit' => 70,
            'balance' => 100,
            'rotative_balance' => 70,
            'currency' => 'BRL',
            'event' => 'transfer.received',
            'fund_type' => 'credit',
        ]);

        $cashOut = app(CashOutAvailability::class);
        $this->assertSame(100.0, $cashOut->availableFor($customer));

        $grant = CreditGrant::create([
            'customer_id' => $customer->id,
            'granted_by_user_id' => User::factory()->create()->id,
            'amount' => 40,
            'available_amount' => 0,
            'reason' => 'Teste de reserva',
        ]);
        CreditDebt::create([
            'credit_grant_id' => $grant->id,
            'debtor_customer_id' => $customer->id,
            'principal' => 40,
            'outstanding_amount' => 40,
        ]);

        $summary = $cashOut->summary($customer);
        $this->assertSame(40.0, $summary['credit_debt_outstanding']);
        $this->assertSame(60.0, $summary['available']);
    }

    public function test_a_credit_classified_mixed_transfer_does_not_fund_cash_out(): void
    {
        $customer = Customer::create(['phone' => '5551999990907', 'balance' => 60, 'rotative_balance' => 40]);
        Movement::create([
            'customer_id' => $customer->id,
            'credit' => 60,
            'rotative_credit' => 40,
            'balance' => 60,
            'rotative_balance' => 40,
            'currency' => 'BRL',
            'event' => 'transfer.received',
            'fund_type' => 'credit',
        ]);

        $cashOut = app(CashOutAvailability::class);
        $this->assertSame(0.0, $cashOut->availableFor($customer));

        $customer->forceFill(['balance' => 85])->save();
        Movement::create([
            'customer_id' => $customer->id,
            'credit' => 25,
            'balance' => 85,
            'currency' => 'BRL',
            'event' => 'transfer.received',
            'fund_type' => 'debit',
        ]);

        $this->assertSame(25.0, $cashOut->availableFor($customer));
    }

    public function test_converted_credit_and_reversed_cash_out_become_withdrawable_debit(): void
    {
        $customer = Customer::create(['phone' => '5551999990904', 'balance' => 55]);
        $this->movement($customer, 'rotative_credit.released', credit: 40);
        $this->movement($customer, 'redeem_pix.submitted', debit: 15);
        $this->movement($customer, 'redeem_pix.reversed', credit: 15);

        $summary = app(CashOutAvailability::class)->summary($customer);

        $this->assertSame(40.0, $summary['converted_credit']);
        $this->assertSame(15.0, $summary['reversed_withdrawals']);
        $this->assertSame(40.0, $summary['available']);
    }

    public function test_minimum_amount_is_configurable_and_applies_after_fees(): void
    {
        config(['mpmm.cash_out.minimum_amount' => 5, 'mpmm.cash_out.fixed_fee' => 1]);
        $customer = Customer::create(['phone' => '5551999990905', 'balance' => 5.99]);
        $this->movement($customer, 'deposit.confirmed', credit: 5.99);

        $cashOut = app(CashOutAvailability::class);
        $this->assertSame(5.0, $cashOut->minimumAmount());
        $this->assertSame(0.0, $cashOut->availableFor($customer));

        $customer->forceFill(['balance' => 6])->save();
        $this->movement($customer, 'deposit.confirmed', credit: 0.01);
        $this->assertSame(5.0, $cashOut->availableFor($customer));
    }

    private function movement(Customer $customer, string $event, float $credit = 0, float $debit = 0): void
    {
        Movement::create([
            'customer_id' => $customer->id,
            'credit' => $credit,
            'debit' => $debit,
            'balance' => 0,
            'currency' => 'BRL',
            'event' => $event,
        ]);
    }
}
