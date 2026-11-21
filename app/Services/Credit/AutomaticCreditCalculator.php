<?php

namespace App\Services\Credit;

use App\Models\Customer;
use App\Models\Movement;

class AutomaticCreditCalculator
{
    public const MAX_LIMIT = 2000;

    public function __construct(private readonly AutomaticCreditWallet $wallet) {}

    public function forCustomer(Customer $customer): array
    {
        $totals = Movement::query()
            ->where('customer_id', $customer->id)
            ->where('currency', 'BRL')
            ->whereIn('event', [
                'deposit.confirmed',
                'deposit.reversed',
                'redeem_pix.submitted',
                'redeem_pix.confirmed',
                'external_pix_payment.confirmed',
            ])
            ->selectRaw('event, SUM(credit) as credits, SUM(debit) as debits')
            ->groupBy('event')
            ->get()
            ->keyBy('event');

        $deposits = (float) ($totals->get('deposit.confirmed')?->credits ?? 0);
        $depositReversals = (float) ($totals->get('deposit.reversed')?->debits ?? 0);
        $withdrawals = (float) collect([
            'redeem_pix.submitted',
            'redeem_pix.confirmed',
            'external_pix_payment.confirmed',
        ])->sum(fn (string $event) => (float) ($totals->get($event)?->debits ?? 0));
        $netDeposits = round($deposits - $depositReversals - $withdrawals, 2);

        return [
            'deposits' => round($deposits, 2),
            'deposit_reversals' => round($depositReversals, 2),
            'withdrawals' => round($withdrawals, 2),
            'net_deposits' => $netDeposits,
            'ideal_limit' => round(min(self::MAX_LIMIT, max(0, $netDeposits)), 2),
            'maximum_limit' => self::MAX_LIMIT,
            'current_limit' => round((float) $customer->limit, 2),
            'current_rotative_balance' => round((float) $customer->rotative_balance, 2),
            'automatic_credit_issued' => $this->wallet->issuedAmount($customer),
        ];
    }
}
