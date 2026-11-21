<?php

namespace App\Services\RotativeWallet;

use App\Models\Customer;
use App\Models\User;

/** Central deny-by-default gate for the restricted rotative-wallet pilot. */
class RotativeWalletAccess
{
    public function enabledFor(Customer $customer): bool
    {
        return in_array($customer->phone, $this->phones('mpmm.rotative_wallet.pilot_phones'), true)
            || (bool) (($customer->metadata ?? [])['rotative_wallet_enabled'] ?? false);
    }

    public function canManage(User $user): bool
    {
        return in_array($user->phone, $this->phones('mpmm.rotative_wallet.manager_phones'), true);
    }

    private function phones(string $key): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($phone) => preg_replace('/\D+/', '', trim((string) $phone)),
            explode(',', (string) config($key, '')),
        ))));
    }
}
