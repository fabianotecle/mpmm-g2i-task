<?php

namespace App\Services\Payments;

use App\Services\Payments\Contracts\PaymentProvider;
use RuntimeException;

class PaymentConfigurationGuard
{
    private const ROUTES = [
        'cash_in.pix_provider',
        'cash_in.boleto_provider',
        'cash_in.credit_card_provider',
        'cash_in.debit_card_provider',
        'cash_out.pix_provider',
    ];

    public function validate(): void
    {
        if (app()->environment(['local', 'testing'])) {
            return;
        }

        foreach (self::ROUTES as $route) {
            $providerName = (string) config("payments.{$route}");

            if ($providerName === '' || $providerName === 'simulated') {
                throw new RuntimeException("A real payment provider is required for payments.{$route}.");
            }

            $driver = config("payments.providers.{$providerName}.driver");
            if (! is_string($driver) || ! is_a($driver, PaymentProvider::class, true)) {
                throw new RuntimeException("Payment provider [{$providerName}] for payments.{$route} is not configured correctly.");
            }
        }

        if ($this->uses('asaas') && blank(config('payments.providers.asaas.api_key'))) {
            throw new RuntimeException('ASAAS_API_KEY is required when Asaas is an active payment provider.');
        }

        if ($this->cashInUses('asaas') && blank(config('payments.providers.asaas.webhook_token'))) {
            throw new RuntimeException('ASAAS_WEBHOOK_TOKEN is required to confirm Asaas cash-in operations.');
        }
    }

    private function uses(string $provider): bool
    {
        return collect(self::ROUTES)->contains(
            fn (string $route) => config("payments.{$route}") === $provider,
        );
    }

    private function cashInUses(string $provider): bool
    {
        return collect(self::ROUTES)
            ->filter(fn (string $route) => str_starts_with($route, 'cash_in.'))
            ->contains(fn (string $route) => config("payments.{$route}") === $provider);
    }
}
