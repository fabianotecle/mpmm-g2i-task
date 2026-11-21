<?php

namespace App\Services\Credit;

use App\Models\Customer;
use App\Services\CashOut\CashOutAvailability;

/** Read-only inputs for a future credit policy. Never grants or changes a limit. */
class CreditRuleProposalService
{
    public function __construct(private readonly CashOutAvailability $cashOut) {}

    public function visibleTo(string $phone): bool
    {
        $configured = array_filter(array_map(
            fn ($candidate) => preg_replace('/\D+/', '', trim($candidate)),
            explode(',', (string) config('mpmm.credit_rules_preview.pilot_phones', '')),
        ));

        return in_array(preg_replace('/\D+/', '', $phone), $configured, true);
    }

    public function forCustomer(Customer $customer): array
    {
        $cashOut = $this->cashOut->summary($customer);
        $reference = max(0, round((float) config('mpmm.credit_rules_preview.universal_reference_amount', 50), 2));

        return [
            'status' => 'simulation_only',
            'customer_id' => $customer->id,
            'currency' => 'BRL',
            'inputs' => [
                'confirmed_deposits' => $cashOut['deposits'],
                'withdrawals' => $cashOut['withdrawals'],
                'net_deposits' => $cashOut['net_deposits'],
                'current_credit_limit' => round((float) $customer->limit, 2),
                'current_debit_balance' => round((float) $customer->balance, 2),
                'current_rotative_balance' => round((float) $customer->rotative_balance, 2),
            ],
            'reference' => [
                'universal_amount' => $reference,
                'monthly_rate_cap_percent' => 1,
                'annual_effective_rate_cap_percent' => 11,
                'monthly_rate_equivalent_to_annual_cap_percent' => round((pow(1.11, 1 / 12) - 1) * 100, 6),
            ],
            'proposed_limit' => null,
            'eligible' => null,
            'pending_decisions' => [
                'eligibility_and_individual_override',
                'net_deposit_lookback_period',
                'formula_for_dynamic_limit',
                'credit_provider_and_funding',
                'interest_and_collection_terms',
            ],
        ];
    }
}
