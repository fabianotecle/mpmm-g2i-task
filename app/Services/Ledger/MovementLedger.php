<?php

namespace App\Services\Ledger;

use App\Models\Customer;
use App\Models\Movement;

class MovementLedger
{
    public function credit(Customer $customer, float $amount, string $event, array $attributes = []): Movement
    {
        $balance = (float) $customer->balance + $amount;
        $currency = $attributes['currency'] ?? 'BRL';
        unset($attributes['currency']);

        $customer->forceFill(['balance' => $balance])->save();

        return Movement::create([
            'customer_id' => $customer->id,
            'debit' => 0,
            'credit' => $amount,
            'balance' => $balance,
            'currency' => $currency,
            'event' => $event,
            ...$attributes,
        ]);
    }

    public function debit(Customer $customer, float $amount, string $event, array $attributes = []): Movement
    {
        $balance = (float) $customer->balance - $amount;
        $currency = $attributes['currency'] ?? 'BRL';
        unset($attributes['currency']);

        $customer->forceFill(['balance' => $balance])->save();

        return Movement::create([
            'customer_id' => $customer->id,
            'debit' => $amount,
            'credit' => 0,
            'balance' => $balance,
            'currency' => $currency,
            'event' => $event,
            ...$attributes,
        ]);
    }
}
