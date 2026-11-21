<?php

namespace App\Services\Bonus;

use App\Models\BonusRule;
use App\Models\Customer;
use App\Models\Movement;
use App\Services\Ledger\MovementLedger;
use App\Services\RotativeWallet\RotativeCreditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BonusAwardService
{
    public function __construct(
        private readonly MovementLedger $ledger,
        private readonly RotativeCreditService $rotativeCredits,
    )
    {
    }

    /**
     * @return list<Movement>
     */
    public function award(string $event, Customer $customer, array $payload = []): array
    {
        return DB::transaction(function () use ($event, $customer, $payload) {
            $lockedCustomer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();

            return $this->awardForLockedCustomer($event, $lockedCustomer, $payload);
        });
    }

    /** @return list<Movement> */
    private function awardForLockedCustomer(string $event, Customer $customer, array $payload): array
    {
        $sourceId = $payload['source_id'] ?? null;

        if ($sourceId === null || $sourceId === '') {
            return [];
        }

        $now = Carbon::now();
        $amount = (float) ($payload['amount'] ?? 0);
        $sourceType = $this->sourceType($payload);
        $awarded = [];

        $rules = BonusRule::query()
            ->where('active', true)
            ->where('event', $event)
            ->where(function ($query) use ($now) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if (! $this->matchesRuleMetadata($rule, $payload)) {
                continue;
            }

            if ($this->belowMinimumAmount($rule, $amount)) {
                continue;
            }

            if ($this->customerAwardLimitReached($rule, $customer)) {
                continue;
            }

            $operationId = $this->operationId($rule, $event, $sourceType, (string) $sourceId, $customer);

            if (Movement::where('external_provider', 'mpmm_bonus')
                ->where('external_operation_id', $operationId)
                ->exists()) {
                continue;
            }

            $rewardAmount = $this->rewardAmount($rule, $amount);

            if ($rewardAmount <= 0) {
                continue;
            }

            $attributes = [
                'origin_customer_id' => $payload['origin_customer_id'] ?? null,
                'conversation_session_id' => $payload['conversation_session_id'] ?? null,
                'external_provider' => 'mpmm_bonus',
                'external_operation_id' => $operationId,
                'metadata' => [
                    'bonus_rule_id' => $rule->id,
                    'bonus_rule_name' => $rule->name,
                    'source_event' => $event,
                    'source_type' => $sourceType,
                    'source_id' => (string) $sourceId,
                    'reward_type' => $rule->reward_type,
                    'reward_value' => (float) $rule->reward_value,
                    'source_amount' => $amount,
                    'source_metadata' => $this->sourceMetadata($payload),
                ],
            ];

            $awarded[] = in_array($event, [BonusEvents::INVITE_SENT_ACCEPTED, BonusEvents::INVITE_ACCEPTED_DEPOSIT], true)
                ? $this->rotativeCredits->creditBonus($customer, $rewardAmount, $attributes)
                : $this->ledger->credit($customer, $rewardAmount, 'bonus.cashback', $attributes);
        }

        return $awarded;
    }

    private function matchesRuleMetadata(BonusRule $rule, array $payload): bool
    {
        $filters = $rule->metadata['filters'] ?? [];

        if (! is_array($filters) || $filters === []) {
            return true;
        }

        foreach ($filters as $key => $accepted) {
            $actual = data_get($payload, $key);

            if ($actual === null) {
                return false;
            }

            $acceptedValues = is_array($accepted) ? $accepted : [$accepted];
            $acceptedValues = array_map(fn ($value) => (string) $value, $acceptedValues);

            if (! in_array((string) $actual, $acceptedValues, true)) {
                return false;
            }
        }

        return true;
    }

    private function belowMinimumAmount(BonusRule $rule, float $amount): bool
    {
        return $rule->min_event_amount !== null && $amount < (float) $rule->min_event_amount;
    }

    private function customerAwardLimitReached(BonusRule $rule, Customer $customer): bool
    {
        if ($rule->max_awards_per_customer === null) {
            return false;
        }

        $prefix = "bonus:{$rule->id}:";
        $suffix = ":{$customer->id}";

        $awards = Movement::where('customer_id', $customer->id)
            ->where('event', 'bonus.cashback')
            ->where('external_provider', 'mpmm_bonus')
            ->where('external_operation_id', 'like', $prefix.'%'.$suffix)
            ->count();

        return $awards >= $rule->max_awards_per_customer;
    }

    private function rewardAmount(BonusRule $rule, float $sourceAmount): float
    {
        if ($rule->reward_type === 'percent') {
            return round($sourceAmount * ((float) $rule->reward_value / 100), 2);
        }

        return round((float) $rule->reward_value, 2);
    }

    private function sourceType(array $payload): string
    {
        $sourceType = $payload['source_type'] ?? 'event';

        return preg_replace('/[^a-z0-9_.-]+/i', '_', (string) $sourceType) ?: 'event';
    }

    private function sourceMetadata(array $payload): array
    {
        return array_filter(
            [
                'ddi' => $payload['ddi'] ?? null,
                'ddd' => $payload['ddd'] ?? null,
                'phone_prefix' => $payload['phone_prefix'] ?? null,
            ],
            fn ($value) => $value !== null && $value !== '',
        );
    }

    private function operationId(BonusRule $rule, string $event, string $sourceType, string $sourceId, Customer $customer): string
    {
        $sourceId = preg_replace('/[^a-z0-9_.-]+/i', '_', $sourceId) ?: 'source';

        return "bonus:{$rule->id}:{$event}:{$sourceType}:{$sourceId}:{$customer->id}";
    }
}
