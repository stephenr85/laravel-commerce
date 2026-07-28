<?php

namespace Rushing\Commerce;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rushing\Commerce\Acp\AgenticCheckout;
use Rushing\Commerce\Acp\Contracts\CheckoutSessionStore;
use Rushing\Commerce\Acp\Contracts\OfferResolver;
use Rushing\Commerce\Acp\Contracts\OrderStore;
use Rushing\Commerce\Acp\Support\EloquentCheckoutSessionStore;
use Rushing\Commerce\Acp\Support\EloquentOrderStore;
use Rushing\Commerce\Acp\Support\FixtureOfferResolver;
use Rushing\Commerce\Billing\BillComposer;
use Rushing\Commerce\Billing\ComponentRegistry;
use Rushing\Commerce\Billing\Pricing\PricingStrategyRegistry;
use Rushing\Commerce\Budget\BudgetGate;
use Rushing\Commerce\Contracts\MerchantResolver;
use Rushing\Commerce\Contracts\PaymentMethodResolver;
use Rushing\Commerce\Contracts\StripeClientFactory;
use Rushing\Commerce\Stripe\ConfigMerchantResolver;
use Rushing\Commerce\Stripe\ConfigStripeClients;
use Rushing\Commerce\Support\NullPaymentMethodResolver;

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
        $this->app->singleton(AutoReload::class);
        $this->app->singleton(Settlements::class);
        $this->app->singleton(BudgetGate::class);

        $this->app->singleton(PricingStrategyRegistry::class);
        $this->app->singleton(ComponentRegistry::class);
        $this->app->singleton(BillComposer::class);

        // The `stripe` driver's seams: a tenancy-aware host rebinds these to
        // resolve the billing party and its own per-tenant credentials.
        $this->app->bind(MerchantResolver::class, ConfigMerchantResolver::class);
        $this->app->bind(StripeClientFactory::class, ConfigStripeClients::class);

        // Auto-reload's card-identity seam: no host bound means no chargeable card
        // (ADR-0131). A host rebinds this over its own card store to arm auto-reload.
        $this->app->bind(PaymentMethodResolver::class, NullPaymentMethodResolver::class);

        // ACP sell-side seam. The Offer/Order ports have working defaults so the
        // whole agentic-checkout loop is dogfoodable out of the box: a fixture
        // resolver stands in for the catalog (slice 02 rebinds it to a real feed),
        // and Eloquent stores persist the session + minimal order. A host swaps any
        // of the three by rebinding its contract.
        $this->app->bind(OfferResolver::class, FixtureOfferResolver::class);
        $this->app->bind(CheckoutSessionStore::class, EloquentCheckoutSessionStore::class);
        $this->app->bind(OrderStore::class, EloquentOrderStore::class);
        $this->app->singleton(AgenticCheckout::class);
    }

    public function boot(): void
    {
        // Single-tenant consumers auto-load these as central migrations; a multi-tenant
        // broker sets commerce.register_migrations=false and publishes them into its
        // per-tenant migration set (the satellite layer flips this).
        if (config('commerce.register_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        // The ACP protocol routes ship as brand-blind definitions; the host mounts
        // them inside its own public tenant group (prefix + tenancy middleware) by
        // calling Route::commerceAcpRoutes(). Mirrors the beam-commerce macro seam.
        Route::macro('commerceAcpRoutes', function (): void {
            Route::prefix(config('commerce.acp.route_root', 'agentic-commerce'))
                ->group(__DIR__.'/../routes/acp.php');
        });

        // Engine-owned wallet funding: a Credit-topup Purchase funds the beneficiary's Wallet,
        // so every host gets top-up funding without wiring its own listener (the Stripe driver
        // only translates the provider event into the Purchase).
        Event::listen(Events\PurchaseCompleted::class, Listeners\FundWalletFromCreditTopup::class);

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
