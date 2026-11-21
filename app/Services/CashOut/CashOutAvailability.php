<?php

namespace App\Services\CashOut;

use App\Models\Customer;
use App\Models\CreditDebt;
use App\Models\Movement;

class CashOutAvailability
{
    private const DEPOSIT_EVENTS = ['deposit.confirmed'];

    private const WITHDRAWAL_EVENTS = [
        'redeem_pix.submitted',
    ];

    private const FEE_EVENTS = ['cash_out.fee'];

    public function availableFor(Customer $customer): float
    {
        $availableBalance = $this->summary($customer)['extended_cash_out'];

        return $this->maximumNetAmount($availableBalance);
    }

    public function minimumAmount(): float
    {
        return max(0.01, $this->money((float) config('mpmm.cash_out.minimum_amount', 0.01)));
    }

    public function feeFor(float $amount): float
    {
        if ($amount <= 0) {
            return 0;
        }

        return $this->money($this->fixedFee() + ($amount * $this->rate()));
    }

    public function summary(Customer $customer): array
    {
        $totals = Movement::where('customer_id', $customer->id)
            ->selectRaw("event, SUM(CASE WHEN fund_type = 'credit' THEN 0 ELSE credit END) as credits, SUM(debit) as debits")
            ->groupBy('event')
            ->get()
            ->keyBy('event');

        $deposits = $this->creditsFor($totals, self::DEPOSIT_EVENTS);
        $withdrawals = $this->debitsFor($totals, self::WITHDRAWAL_EVENTS);
        $fees = $this->debitsFor($totals, self::FEE_EVENTS);
        $transfersReceived = $this->creditsFor($totals, ['transfer.received']);
        $convertedCredit = $this->creditsFor($totals, ['rotative_credit.released']);
        $reversedWithdrawals = $this->creditsFor($totals, ['redeem_pix.reversed']);
        $transfersSent = $this->debitsFor($totals, ['transfer.sent']);
        $netDeposits = $deposits - $withdrawals;
        $security = max(0, $netDeposits - $fees);
        // Only debit-denominated movements fund a withdrawal. Rotative credits remain in rotative_balance.
        $normal = max(0, $netDeposits - $fees + $transfersReceived + $convertedCredit + $reversedWithdrawals - $transfersSent);
        $creditGranted = max(0, (float) $customer->limit);
        $creditUsed = max(0, -(float) $customer->balance);
        $creditAvailable = max(0, $creditGranted - $creditUsed);
        $creditDebtOutstanding = (float) CreditDebt::query()
            ->where('debtor_customer_id', $customer->id)
            ->where('status', 'open')
            ->sum('outstanding_amount');
        $walletCapacity = max(0, (float) $customer->balance - $creditDebtOutstanding);
        // Limite de crédito é informativo e nunca pode financiar um resgate.
        $extended = max(0, min($normal, $walletCapacity));

        return [
            'deposits' => $this->money($deposits),
            'withdrawals' => $this->money($withdrawals),
            'fees' => $this->money($fees),
            'security_cash_out' => $this->money($security),
            'transfers_received' => $this->money($transfersReceived),
            'converted_credit' => $this->money($convertedCredit),
            'reversed_withdrawals' => $this->money($reversedWithdrawals),
            'transfers_sent' => $this->money($transfersSent),
            'net_transfers' => $this->money($transfersReceived - $transfersSent),
            'normal_cash_out' => $this->money($normal),
            'credit_granted' => $this->money($creditGranted),
            'credit_used' => $this->money($creditUsed),
            'credit_available' => $this->money($creditAvailable),
            'credit_debt_outstanding' => $this->money($creditDebtOutstanding),
            'wallet_capacity' => $this->money($walletCapacity),
            'extended_cash_out' => $this->money($extended),
            'fixed_fee' => $this->money($this->fixedFee()),
            'minimum_amount' => $this->minimumAmount(),
            'percentage_rate' => $this->money($this->rate() * 100),
            'available' => $this->maximumNetAmount($extended),
            'net_deposits' => $this->money($netDeposits),
        ];
    }

    private function maximumNetAmount(float $grossCapacity): float
    {
        if ($grossCapacity <= $this->fixedFee()) {
            return 0;
        }

        $net = $this->money(($grossCapacity - $this->fixedFee()) / (1 + $this->rate()));

        while ($net > 0 && $net + $this->feeFor($net) > $grossCapacity + 0.000001) {
            $net = $this->money($net - 0.01);
        }

        return $net >= $this->minimumAmount() ? $net : 0;
    }

    private function creditsFor($totals, array $events): float
    {
        return (float) collect($events)->sum(fn (string $event) => (float) ($totals->get($event)?->credits ?? 0));
    }

    private function debitsFor($totals, array $events): float
    {
        return (float) collect($events)->sum(fn (string $event) => (float) ($totals->get($event)?->debits ?? 0));
    }

    private function fixedFee(): float
    {
        return max(0, (float) config('mpmm.cash_out.fixed_fee', 0));
    }

    private function rate(): float
    {
        return max(0, (float) config('mpmm.cash_out.percentage_rate', 0)) / 100;
    }

    private function money(float $amount): float
    {
        return round($amount, 2);
    }
}
