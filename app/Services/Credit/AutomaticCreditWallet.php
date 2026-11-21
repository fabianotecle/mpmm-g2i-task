<?php

namespace App\Services\Credit;

use App\Models\CreditGrant;
use App\Models\Customer;
use App\Models\Movement;
use App\Models\RotativeLot;
use App\Models\User;
use App\Services\RotativeWallet\RotativeCreditService;
use Illuminate\Support\Facades\DB;
use LogicException;

class AutomaticCreditWallet
{
    public function __construct(
        private readonly RotativeCreditService $credits,
        private readonly CreditLimitManager $limits,
    ) {}

    public function issuedAmount(Customer $customer): float
    {
        $granted = (float) CreditGrant::query()
            ->where('customer_id', $customer->id)
            ->where('rules->source', 'automatic_credit')
            ->sum('amount');
        $reduced = (float) Movement::query()
            ->where('customer_id', $customer->id)
            ->where('event', 'rotative_credit.automatic_reduced')
            ->sum('rotative_debit');
        $restored = (float) Movement::query()
            ->where('customer_id', $customer->id)
            ->where('event', 'rotative_credit.automatic_restored')
            ->sum('rotative_credit');

        return round(max(0, $granted - $reduced + $restored), 2);
    }

    public function adjustTo(Customer $customer, User $manager, float $target, array $metadata = []): void
    {
        DB::transaction(function () use ($customer, $manager, $target, $metadata) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $difference = round($target - $this->issuedAmount($customer), 2);

            if ($difference > 0) {
                $this->credits->grant($customer, $manager, $difference, 'Crédito automático sobre depósito líquido',
                    ['source' => 'automatic_credit']);
                $customer->refresh();
            } elseif ($difference < 0) {
                $this->reduce($customer, -$difference, 'manual_adjustment', null, true);
            }

            $this->limits->set($customer, $target, $metadata);
        });
    }

    public function reduceForWithdrawal(Customer $customer, float $amount, Movement $withdrawal): float
    {
        return DB::transaction(function () use ($customer, $amount, $withdrawal) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $reduced = $this->reduce($customer, $amount, 'asaas_withdrawal', $withdrawal->id);

            if ($reduced > 0) {
                $this->limits->set($customer, max(0, round((float) $customer->limit - $reduced, 2)), [
                    'source' => 'asaas_withdrawal',
                    'withdrawal_movement_id' => $withdrawal->id,
                ]);
            }

            return $reduced;
        });
    }

    public function restoreFailedWithdrawal(Customer $customer, Movement $withdrawal): float
    {
        return DB::transaction(function () use ($customer, $withdrawal) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $offset = Movement::query()
                ->where('customer_id', $customer->id)
                ->where('event', 'rotative_credit.automatic_reduced')
                ->where('metadata->withdrawal_movement_id', $withdrawal->id)
                ->lockForUpdate()
                ->first();

            if (! $offset || Movement::query()
                ->where('event', 'rotative_credit.automatic_restored')
                ->where('metadata->offset_movement_id', $offset->id)
                ->exists()) {
                return 0;
            }

            $amount = 0;
            foreach ($offset->metadata['parts'] ?? [] as $part) {
                $lot = RotativeLot::query()->lockForUpdate()->findOrFail($part['lot_id']);
                $grant = CreditGrant::query()->lockForUpdate()->findOrFail($lot->credit_grant_id);
                $value = (float) $part['amount'];
                $lot->forceFill([
                    'remaining_amount' => round((float) $lot->remaining_amount + $value, 2),
                    'status' => 'blocked',
                ])->save();
                $grant->forceFill(['available_amount' => round((float) $grant->available_amount + $value, 2)])->save();
                $amount = round($amount + $value, 2);
            }

            if ($amount <= 0) {
                return 0;
            }

            $customer->forceFill(['rotative_balance' => round((float) $customer->rotative_balance + $amount, 2)])->save();
            Movement::create([
                'customer_id' => $customer->id,
                'debit' => 0,
                'credit' => 0,
                'balance' => $customer->balance,
                'rotative_debit' => 0,
                'rotative_credit' => $amount,
                'rotative_balance' => $customer->rotative_balance,
                'fund_type' => 'credit',
                'currency' => 'BRL',
                'event' => 'rotative_credit.automatic_restored',
                'metadata' => [
                    'source' => 'asaas_withdrawal_failed',
                    'description' => 'Estorno da baixa do crédito automático após descarga Pix não concluída',
                    'withdrawal_movement_id' => $withdrawal->id,
                    'offset_movement_id' => $offset->id,
                ],
            ]);
            $this->limits->set($customer, min(AutomaticCreditCalculator::MAX_LIMIT,
                round((float) $customer->limit + $amount, 2)), [
                    'source' => 'asaas_withdrawal_failed',
                    'withdrawal_movement_id' => $withdrawal->id,
                ]);

            return $amount;
        });
    }

    private function reduce(Customer $customer, float $amount, string $reason, ?int $withdrawalId, bool $requireFull = false): float
    {
        $grantIds = CreditGrant::query()
            ->where('customer_id', $customer->id)
            ->where('rules->source', 'automatic_credit')
            ->pluck('id');
        if ($grantIds->isEmpty()) {
            if ($requireFull) {
                throw new LogicException('Não há crédito automático disponível para reduzir até o valor solicitado.');
            }

            return 0;
        }

        $remaining = round($amount, 2);
        $parts = [];
        $lots = RotativeLot::query()
            ->whereIn('credit_grant_id', $grantIds)
            ->where('current_customer_id', $customer->id)
            ->whereNull('credit_debt_id')
            ->where('status', 'blocked')
            ->where('remaining_amount', '>', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($requireFull && round((float) $lots->sum('remaining_amount'), 2) < $remaining) {
            throw new LogicException('Parte do crédito já foi transferida; não é possível reduzir até o valor solicitado.');
        }

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }
            $part = round(min($remaining, (float) $lot->remaining_amount), 2);
            $left = round((float) $lot->remaining_amount - $part, 2);
            $lot->forceFill(['remaining_amount' => $left, 'status' => $left === 0.0 ? 'cancelled' : 'blocked'])->save();
            CreditGrant::query()->whereKey($lot->credit_grant_id)->decrement('available_amount', $part);
            $parts[] = ['lot_id' => $lot->id, 'amount' => $part];
            $remaining = round($remaining - $part, 2);
        }

        $reduced = round($amount - $remaining, 2);
        if ($reduced <= 0) {
            return 0;
        }

        $customer->forceFill(['rotative_balance' => round((float) $customer->rotative_balance - $reduced, 2)])->save();
        Movement::create([
            'customer_id' => $customer->id,
            'debit' => 0,
            'credit' => 0,
            'balance' => $customer->balance,
            'rotative_debit' => $reduced,
            'rotative_credit' => 0,
            'rotative_balance' => $customer->rotative_balance,
            'fund_type' => 'credit',
            'currency' => 'BRL',
            'event' => 'rotative_credit.automatic_reduced',
            'metadata' => [
                'source' => $reason,
                'description' => $reason === 'asaas_withdrawal'
                    ? 'Baixa do crédito automático vinculada à descarga Pix'
                    : 'Ajuste manual do crédito automático',
                'withdrawal_movement_id' => $withdrawalId,
                'parts' => $parts,
            ],
        ]);

        return $reduced;
    }
}
