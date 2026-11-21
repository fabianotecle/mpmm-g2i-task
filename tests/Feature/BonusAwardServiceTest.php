<?php

namespace Tests\Feature;

use App\Models\BonusRule;
use App\Models\Customer;
use App\Models\Movement;
use App\Services\Bonus\BonusAwardService;
use App\Services\Bonus\BonusEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BonusAwardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_awards_fixed_bonus_once_per_source(): void
    {
        $customer = Customer::create([
            'phone' => '5551999990001',
            'status' => 'active',
            'balance' => 100,
        ]);

        BonusRule::create([
            'name' => 'Primeiro deposito',
            'event' => BonusEvents::DEPOSIT_FIRST,
            'active' => true,
            'reward_type' => 'fixed',
            'reward_value' => 10,
        ]);

        $service = app(BonusAwardService::class);

        $first = $service->award(BonusEvents::DEPOSIT_FIRST, $customer, [
            'source_type' => 'movement',
            'source_id' => 123,
            'amount' => 200,
        ]);

        $second = $service->award(BonusEvents::DEPOSIT_FIRST, $customer->refresh(), [
            'source_type' => 'movement',
            'source_id' => 123,
            'amount' => 200,
        ]);

        $this->assertCount(1, $first);
        $this->assertSame([], $second);
        $this->assertSame('110.00', $customer->refresh()->balance);
        $this->assertSame(1, Movement::where('event', 'bonus.cashback')->count());
    }

    public function test_awards_percent_bonus_when_minimum_amount_matches(): void
    {
        $customer = Customer::create([
            'phone' => '5551999990002',
            'status' => 'active',
            'balance' => 0,
        ]);

        BonusRule::create([
            'name' => 'Cinco por cento acima de cem',
            'event' => BonusEvents::DEPOSIT_ABOVE_AMOUNT,
            'active' => true,
            'reward_type' => 'percent',
            'reward_value' => 5,
            'min_event_amount' => 100,
        ]);

        $service = app(BonusAwardService::class);

        $service->award(BonusEvents::DEPOSIT_ABOVE_AMOUNT, $customer, [
            'source_type' => 'movement',
            'source_id' => 321,
            'amount' => 90,
        ]);

        $awards = $service->award(BonusEvents::DEPOSIT_ABOVE_AMOUNT, $customer->refresh(), [
            'source_type' => 'movement',
            'source_id' => 322,
            'amount' => 200,
        ]);

        $this->assertCount(1, $awards);
        $this->assertSame('10.00', $customer->refresh()->balance);
        $this->assertSame('10.00', Movement::where('event', 'bonus.cashback')->sole()->credit);
    }

    public function test_respects_customer_award_limit(): void
    {
        $customer = Customer::create([
            'phone' => '5551999990003',
            'status' => 'active',
            'balance' => 0,
        ]);

        BonusRule::create([
            'name' => 'Bonus unico por transferencia',
            'event' => BonusEvents::TRANSFER_SENT,
            'active' => true,
            'reward_type' => 'fixed',
            'reward_value' => 2,
            'max_awards_per_customer' => 1,
        ]);

        $service = app(BonusAwardService::class);

        $service->award(BonusEvents::TRANSFER_SENT, $customer, [
            'source_type' => 'movement',
            'source_id' => 1,
            'amount' => 20,
        ]);

        $service->award(BonusEvents::TRANSFER_SENT, $customer->refresh(), [
            'source_type' => 'movement',
            'source_id' => 2,
            'amount' => 20,
        ]);

        $this->assertSame('2.00', $customer->refresh()->balance);
        $this->assertSame(1, Movement::where('event', 'bonus.cashback')->count());
    }
}
