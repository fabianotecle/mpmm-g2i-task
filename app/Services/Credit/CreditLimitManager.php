<?php

namespace App\Services\Credit;

use App\Models\Customer;
use App\Models\Movement;
use Illuminate\Support\Facades\DB;

class CreditLimitManager
{
    public function set(Customer $customer, float $limit, array $metadata): ?Movement
    {
        return DB::transaction(function () use ($customer, $limit, $metadata) {
            $conversationSessionId = $metadata['conversation_session_id'] ?? null;
            unset($metadata['conversation_session_id']);

            $lockedCustomer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $previousLimit = (float) $lockedCustomer->limit;

            if (round($previousLimit, 2) === round($limit, 2)) {
                $customer->setRawAttributes($lockedCustomer->getAttributes(), true);

                return null;
            }

            $lockedCustomer->forceFill(['limit' => $limit])->save();
            $customer->setRawAttributes($lockedCustomer->getAttributes(), true);

            return Movement::create([
                'customer_id' => $lockedCustomer->id,
                'conversation_session_id' => $conversationSessionId,
                'debit' => 0,
                'credit' => 0,
                'balance' => $lockedCustomer->balance,
                'currency' => 'BRL',
                'event' => 'credit_limit.updated',
                'metadata' => [
                    'previous_limit' => $previousLimit,
                    'new_limit' => $limit,
                    ...$metadata,
                ],
            ]);
        });
    }
}
