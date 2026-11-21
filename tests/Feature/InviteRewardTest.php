<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CreditDebt;
use App\Models\Movement;
use App\Models\User;
use App\Services\Invites\InviteDepositBonus;
use App\Services\Invites\InviteTracker;
use App\Services\Ledger\MovementLedger;
use App\Services\CashOut\CashOutAvailability;
use App\Services\RotativeWallet\RotativeCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InviteRewardTest extends TestCase
{
    use RefreshDatabase;

    public function test_acceptance_pays_one_real_to_inviter_only_once(): void
    {
        [$inviter, $recipient] = $this->customers();
        $tracker = app(InviteTracker::class);

        $this->assertNotNull($tracker->markAccepted($recipient, $inviter->invite_code));
        $this->assertNotNull($tracker->markAccepted($recipient, $inviter->invite_code));

        $this->assertSame('0.00', $inviter->fresh()->balance);
        $this->assertSame('1.00', $inviter->fresh()->rotative_balance);
        $this->assertSame('1.00', $inviter->fresh()->bonus_credit_balance);
        $this->assertSame('0.00', $recipient->fresh()->balance);
        $this->assertSame(0.0, app(CashOutAvailability::class)->availableFor($inviter->fresh()));
        $this->assertDatabaseHas('movements', [
            'customer_id' => $inviter->id,
            'event' => 'bonus.cashback',
            'fund_type' => 'credit',
            'rotative_credit' => 1,
            'credit' => 0,
        ]);
    }

    public function test_qualifying_deposit_pays_ten_reals_once_per_accepted_invite(): void
    {
        [$inviter, $recipient] = $this->customers();
        app(InviteTracker::class)->markAccepted($recipient, $inviter->invite_code);
        $rewards = app(InviteDepositBonus::class);

        $this->assertSame([], $rewards->award($this->deposit($recipient, 40, 'deposit.simulated')));
        $first = $this->deposit($recipient, 50, 'deposit.confirmed');
        $this->assertCount(1, $rewards->award($first));
        $this->assertSame([], $rewards->award($first));
        $this->assertSame([], $rewards->award($this->deposit($recipient, 70, 'deposit.confirmed')));

        $this->assertSame('0.00', $inviter->fresh()->balance);
        $this->assertSame('11.00', $inviter->fresh()->rotative_balance);
        $this->assertSame('11.00', $inviter->fresh()->bonus_credit_balance);
        $this->assertSame(2, Movement::where('customer_id', $inviter->id)->where('event', 'bonus.cashback')->count());
    }

    public function test_unaccepted_invite_does_not_pay_deposit_bonus(): void
    {
        [$inviter, $recipient] = $this->customers();

        $this->assertSame([], app(InviteDepositBonus::class)->award($this->deposit($recipient, 50, 'deposit.confirmed')));
        $this->assertSame('0.00', $inviter->fresh()->balance);
    }

    public function test_invite_credit_can_move_between_members_without_becoming_withdrawable(): void
    {
        [$inviter, $recipient] = $this->customers();
        app(InviteTracker::class)->markAccepted($recipient, $inviter->invite_code);
        app(InviteDepositBonus::class)->award($this->deposit($recipient, 50, 'deposit.confirmed'));
        $third = Customer::create(['phone' => '5551999990413', 'balance' => 0]);
        $fourth = Customer::create(['phone' => '5551999990414', 'balance' => 0]);
        $wallet = app(RotativeCreditService::class);

        $wallet->transfer($inviter->fresh(), $third->fresh(), 11, [], []);
        $this->assertSame('0.00', $inviter->fresh()->rotative_balance);
        $this->assertSame('11.00', $third->fresh()->rotative_balance);
        $this->assertSame('11.00', $third->fresh()->bonus_credit_balance);
        $this->assertSame(0.0, app(CashOutAvailability::class)->availableFor($third->fresh()));

        $wallet->transfer($third->fresh(), $fourth->fresh(), 11, [], []);
        $this->assertSame('11.00', $fourth->fresh()->bonus_credit_balance);
        $this->assertSame(0.0, app(CashOutAvailability::class)->availableFor($fourth->fresh()));
        $this->assertSame(11.0, (float) Movement::where('customer_id', $fourth->id)
            ->where('event', 'transfer.received')->sole()->bonus_credit_credit);
    }

    public function test_transferring_only_invite_credit_does_not_show_loan_warning(): void
    {
        [$inviter, $recipient] = $this->customers();
        app(InviteTracker::class)->markAccepted($recipient, $inviter->invite_code);
        $destination = Customer::create(['phone' => '5551999990413', 'balance' => 0]);

        $sessionId = $this->postJson('/api/conversations/start', [
            'external_user_id' => $inviter->phone,
        ])->assertCreated()->json('session_id');
        $this->postJson("/api/conversations/{$sessionId}/messages", ['message' => 'Transferir'])->assertOk();
        $this->postJson("/api/conversations/{$sessionId}/messages", ['message' => $destination->phone])->assertOk();
        $this->postJson("/api/conversations/{$sessionId}/messages", ['message' => '1'])
            ->assertOk()->assertJsonPath('state', 'transfer.waiting_confirmation');
        $this->postJson("/api/conversations/{$sessionId}/messages", ['message' => 'Confirmar'])
            ->assertOk()->assertJsonPath('state', 'transfer.waiting_transfer_result_action');

        $this->assertSame('1.00', $destination->fresh()->bonus_credit_balance);
        $this->assertSame('0.00', $inviter->fresh()->bonus_credit_balance);
    }

    public function test_mixed_bonus_and_loan_transfer_tracks_only_the_loan_as_debt(): void
    {
        [$inviter, $recipient] = $this->customers();
        app(InviteTracker::class)->markAccepted($recipient, $inviter->invite_code);
        $destination = Customer::create(['phone' => '5551999990413', 'balance' => 0]);
        $wallet = app(RotativeCreditService::class);
        $wallet->grant($inviter->fresh(), User::factory()->create(), 10, 'Crédito de teste');

        $wallet->transfer($inviter->fresh(), $destination->fresh(), 11, [], []);

        $this->assertSame('11.00', $destination->fresh()->rotative_balance);
        $this->assertSame('1.00', $destination->fresh()->bonus_credit_balance);
        $this->assertSame('10.00', CreditDebt::where('debtor_customer_id', $inviter->id)->sole()->principal);
        $this->assertSame(1.0, (float) Movement::where('customer_id', $destination->id)
            ->where('event', 'transfer.received')->sole()->bonus_credit_credit);
    }

    private function customers(): array
    {
        return [
            Customer::create(['phone' => '5551999990411', 'invite_code' => 'convite-bonus', 'balance' => 0]),
            Customer::create(['phone' => '5551999990412', 'balance' => 0]),
        ];
    }

    private function deposit(Customer $recipient, float $amount, string $event): Movement
    {
        return app(MovementLedger::class)->credit($recipient->fresh(), $amount, $event);
    }
}
