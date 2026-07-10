<?php

namespace Rushing\Commerce;

use Illuminate\Support\ServiceProvider;
use Rushing\Commerce\Billing\BillComposer;
use Rushing\Commerce\Billing\ComponentRegistry;
use Rushing\Commerce\Billing\Pricing\PricingStrategyRegistry;
use Rushing\Commerce\Budget\BudgetGate;
use Rushing\Commerce\Contracts\MerchantResolver;
use Rushing\Commerce\Contracts\StripeClientFactory;
use Rushing\Commerce\Stripe\ConfigMerchantResolver;
use Rushing\Commerce\Stripe\ConfigStripeClients;

/**
 * Registers the shared commerce engine. The money-in module (manager + `MoneyIn`
 * service) is bound as singletons; it is independent of any payment rail, so the
 * metering plane is usable with Cashier absent (a cost-only tenant like thingson.tv).
 */
class CommerceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/commerce.php', 'commerce');

        $this->app->singleton(MoneyInManager::class, fn ($app) => new MoneyInManager($app));
        $this->app->singleton(MoneyIn::class);
        $this->app->singleton(Gifts::class);
        $this->app->singleton(Wallets::class);
        $this->app->singleton(Settlements::class);
        $this->app->singleton(BudgetGate::class);

        $this->app->singleton(PricingStrategyRegistry::class);
        $this->app->singleton(ComponentRegistry::class);
        $this->app->singleton(BillComposer::class);

        // The `stripe` driver's seams: a tenancy-aware host rebinds these to
        // resolve the billing party and its own per-tenant credentials.
        $this->app->bind(MerchantResolver::class, ConfigMerchantResolver::class);
        $this->app->bind(StripeClientFactory::class, ConfigStripeClients::class);
    }

    public function boot(): void
    {
        // Single-tenant consumers auto-load these as central migrations; a multi-tenant
        // broker sets commerce.register_migrations=false and publishes them into its
        // per-tenant migration set (the satellite layer flips this).
        if (config('commerce.register_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/commerce.php' => $this->app->configPath('commerce.php'),
            ], 'commerce-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'commerce-migrations');

            $this->commands([
                Console\StripeListenCommand::class,
            ]);
        }
    }
}
