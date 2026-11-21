<?php

namespace App\Services\RotativeWallet;

use App\Models\CreditGrant;
use App\Models\CreditDebt;
use App\Models\CreditDebtPayment;
use App\Models\Customer;
use App\Models\Movement;
use App\Models\RotativeLot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RotativeCreditService
{
    public function availableToTransfer(?Customer $customer): float
    {
        if (! $customer) {
            return 0;
        }

        return round(max(0, (float) $customer->balance)
            + max(0, (float) $customer->rotative_balance), 2);
    }

    public function transfer(Customer $sender, Customer $recipient, float $amount, array $sentAttributes, array $receivedAttributes): array
    {
        $normalAmount = min($amount, max(0, (float) $sender->balance));
        $rotativeAmount = round($amount - $normalAmount, 2);
        if ($rotativeAmount > (float) $sender->rotative_balance) {
            throw new \LogicException('Insufficient wallet balance.');
        }
        $bonusAmount = min($rotativeAmount, max(0, (float) $sender->bonus_credit_balance));
        $loanAmount = round($rotativeAmount - $bonusAmount, 2);

        $sender->forceFill([
            'balance' => (float) $sender->balance - $normalAmount,
            'rotative_balance' => (float) $sender->rotative_balance - $rotativeAmount,
            'bonus_credit_balance' => (float) $sender->bonus_credit_balance - $bonusAmount,
        ])->save();
        $recipient->forceFill([
            'balance' => (float) $recipient->balance + $normalAmount,
            'rotative_balance' => (float) $recipient->rotative_balance + $rotativeAmount,
            'bonus_credit_balance' => (float) $recipient->bonus_credit_balance + $bonusAmount,
        ])->save();

        $sent = Movement::create([
            'customer_id' => $sender->id, 'debit' => $normalAmount, 'credit' => 0,
            'balance' => $sender->balance, 'rotative_debit' => $rotativeAmount, 'rotative_credit' => 0,
            'rotative_balance' => $sender->rotative_balance, 'bonus_credit_debit' => $bonusAmount,
            'fund_type' => $rotativeAmount > 0 ? 'credit' : 'debit',
            'currency' => 'BRL', 'event' => 'transfer.sent', ...$sentAttributes,
        ]);
        $received = Movement::create([
            'customer_id' => $recipient->id, 'origin_customer_id' => $sender->id,
            'debit' => 0, 'credit' => $normalAmount, 'balance' => $recipient->balance,
            'rotative_debit' => 0, 'rotative_credit' => $rotativeAmount, 'rotative_balance' => $recipient->rotative_balance,
            'bonus_credit_credit' => $bonusAmount,
            'fund_type' => $rotativeAmount > 0 ? 'credit' : 'debit', 'currency' => 'BRL', 'event' => 'transfer.received',
            ...$receivedAttributes,
        ]);

        if ($loanAmount > 0) {
            $this->moveLots($sender, $recipient, $loanAmount);
        }

        return ['sent' => $sent, 'received' => $received, 'normal_amount' => $normalAmount, 'rotative_amount' => $rotativeAmount];
    }

    public function creditBonus(Customer $customer, float $amount, array $attributes = []): Movement
    {
        $customer->forceFill([
            'rotative_balance' => round((float) $customer->rotative_balance + $amount, 2),
            'bonus_credit_balance' => round((float) $customer->bonus_credit_balance + $amount, 2),
        ])->save();

        return Movement::create([
            'customer_id' => $customer->id,
            'debit' => 0,
            'credit' => 0,
            'balance' => $customer->balance,
            'rotative_debit' => 0,
            'rotative_credit' => $amount,
            'rotative_balance' => $customer->rotative_balance,
            'bonus_credit_credit' => $amount,
            'fund_type' => 'credit',
            'currency' => 'BRL',
            'event' => 'bonus.cashback',
            ...$attributes,
        ]);
    }

    public function grant(Customer $customer, User $manager, float $amount, string $reason, array $rules = []): CreditGrant
    {
        return DB::transaction(function () use ($customer, $manager, $amount, $reason, $rules) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $grant = CreditGrant::create([
                'customer_id' => $customer->id, 'granted_by_user_id' => $manager->id,
                'amount' => $amount, 'available_amount' => $amount, 'reason' => $reason,
                'rules' => $rules,
            ]);
            $rotativeBalance = (float) $customer->rotative_balance + $amount;
            $customer->forceFill(['rotative_balance' => $rotativeBalance])->save();
            RotativeLot::create([
                'credit_grant_id' => $grant->id, 'current_customer_id' => $customer->id,
                'amount' => $amount, 'remaining_amount' => $amount, 'received_at' => now(),
                'eligible_after' => now()->addMonth()->endOfMonth()->addDays(10),
            ]);
            Movement::create([
                'customer_id' => $customer->id, 'debit' => 0, 'credit' => 0,
                'balance' => $customer->balance, 'rotative_debit' => 0, 'rotative_credit' => $amount,
                'rotative_balance' => $rotativeBalance, 'fund_type' => 'credit', 'currency' => 'BRL',
                'event' => 'rotative_credit.granted', 'metadata' => ['credit_grant_id' => $grant->id, 'reason' => $reason, 'granted_by_user_id' => $manager->id],
            ]);
            return $grant;
        });
    }

    public function payDebt(CreditDebt $debt, User $manager, float $amount, ?string $note = null): CreditDebt
    {
        return DB::transaction(function () use ($debt, $manager, $amount, $note) {
            $debt = CreditDebt::query()->lockForUpdate()->findOrFail($debt->id);
            if ($debt->status !== 'open' || $amount > (float) $debt->outstanding_amount) {
                throw new \LogicException('Invalid debt payment amount.');
            }

            $debtor = Customer::query()->lockForUpdate()->findOrFail($debt->debtor_customer_id);
            if ($amount > max(0, (float) $debtor->balance)) {
                throw new \LogicException('Insufficient debit balance for this debt payment.');
            }

            $debtor->forceFill(['balance' => (float) $debtor->balance - $amount])->save();
            $remaining = round((float) $debt->outstanding_amount - $amount, 2);
            $debt->forceFill([
                'outstanding_amount' => $remaining,
                'status' => $remaining === 0.0 ? 'paid' : 'open',
                'settled_at' => $remaining === 0.0 ? now() : null,
            ])->save();
            $payment = CreditDebtPayment::create([
                'credit_debt_id' => $debt->id, 'customer_id' => $debtor->id,
                'recorded_by_user_id' => $manager->id, 'amount' => $amount, 'note' => $note,
            ]);
            Movement::create([
                'customer_id' => $debtor->id, 'debit' => $amount, 'credit' => 0,
                'balance' => $debtor->balance, 'rotative_debit' => 0, 'rotative_credit' => 0,
                'rotative_balance' => $debtor->rotative_balance, 'fund_type' => 'debit', 'currency' => 'BRL',
                'event' => 'rotative_credit.debt_payment',
                'metadata' => ['credit_debt_id' => $debt->id, 'credit_debt_payment_id' => $payment->id, 'note' => $note],
            ]);

            if ($remaining === 0.0) {
                $this->releaseEligibleLots($debt->id);
            }

            return $debt->fresh();
        });
    }

    public function releaseEligibleLots(?int $debtId = null): int
    {
        $released = 0;
        $lots = RotativeLot::query()
            ->where('status', 'blocked')
            ->whereNotNull('credit_debt_id')
            ->where('eligible_after', '<=', now())
            ->when($debtId, fn ($query) => $query->where('credit_debt_id', $debtId))
            ->lockForUpdate()
            ->get();

        foreach ($lots as $lot) {
            $debt = CreditDebt::query()->lockForUpdate()->find($lot->credit_debt_id);
            if (! $debt || $debt->status !== 'paid' || (float) $lot->remaining_amount <= 0) {
                continue;
            }
            $customer = Customer::query()->lockForUpdate()->findOrFail($lot->current_customer_id);
            $amount = (float) $lot->remaining_amount;
            $customer->forceFill([
                'balance' => (float) $customer->balance + $amount,
                'rotative_balance' => (float) $customer->rotative_balance - $amount,
            ])->save();
            $lot->forceFill(['status' => 'released'])->save();
            Movement::create([
                'customer_id' => $customer->id, 'debit' => 0, 'credit' => $amount,
                'balance' => $customer->balance, 'rotative_debit' => $amount, 'rotative_credit' => 0,
                'rotative_balance' => $customer->rotative_balance, 'fund_type' => 'debit', 'currency' => 'BRL',
                'event' => 'rotative_credit.released',
                'metadata' => ['credit_debt_id' => $debt->id, 'rotative_lot_id' => $lot->id],
            ]);
            $released++;
        }

        return $released;
    }

    private function moveLots(Customer $sender, Customer $recipient, float $amount): void
    {
        $remaining = $amount;
        foreach (RotativeLot::where('current_customer_id', $sender->id)->where('status', 'blocked')->where('remaining_amount', '>', 0)->oldest('received_at')->lockForUpdate()->get() as $lot) {
            $part = min($remaining, (float) $lot->remaining_amount);
            $debtId = $lot->credit_debt_id;
            if (! $debtId) {
                $debt = CreditDebt::create([
                    'credit_grant_id' => $lot->credit_grant_id, 'debtor_customer_id' => $sender->id,
                    'principal' => $part, 'outstanding_amount' => $part,
                ]);
                CreditGrant::whereKey($lot->credit_grant_id)->lockForUpdate()->decrement('available_amount', $part);
                $debtId = $debt->id;
            }
            $lot->forceFill(['remaining_amount' => (float) $lot->remaining_amount - $part, 'status' => (float) $lot->remaining_amount <= $part ? 'moved' : 'blocked'])->save();
            RotativeLot::create([
                'credit_grant_id' => $lot->credit_grant_id, 'credit_debt_id' => $debtId,
                'current_customer_id' => $recipient->id, 'amount' => $part, 'remaining_amount' => $part,
                'received_at' => now(), 'eligible_after' => now()->addMonth()->endOfMonth()->addDays(10),
            ]);
            $remaining = round($remaining - $part, 2);
            if ($remaining <= 0) return;
        }
        throw new \LogicException('Rotative lots are inconsistent.');
    }
}
