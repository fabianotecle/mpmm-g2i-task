<?php

namespace App\Providers;

use App\Services\Audit\CustomerAuditContext;
use App\Services\Payments\Contracts\PaymentProvider;
use App\Services\Payments\PaymentConfigurationGuard;
use InvalidArgumentException;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CustomerAuditContext::class);

        $this->app->bind(PaymentProvider::class, function ($app) {
            $name = config('payments.default', 'simulated');
            $provider = config("payments.providers.{$name}");
            $driver = $provider['driver'] ?? null;

            if (! $driver) {
                throw new InvalidArgumentException("Payment provider [{$name}] is not configured.");
            }

            if (! is_a($driver, PaymentProvider::class, true)) {
                throw new InvalidArgumentException("Payment provider driver [{$driver}] must implement ".PaymentProvider::class.'.');
            }

            return $app->make($driver);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->make(PaymentConfigurationGuard::class)->validate();
    }
}
