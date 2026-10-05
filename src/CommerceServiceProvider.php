<?php

namespace Rushing\Commerce;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Rushing\Commerce\Acp\AgenticCheckout;
use Rushing\Commerce\Acp\Contracts\BeneficiaryResolver;
use Rushing\Commerce\Acp\Contracts\CheckoutSessionStore;
use Rushing\Commerce\Acp\Contracts\OfferResolver;
use Rushing\Commerce\Acp\Contracts\OrderStore;
use Rushing\Commerce\Acp\Feed\AcpFeedRegistry;
use Rushing\Commerce\Acp\Support\EloquentCheckoutSessionStore;
use Rushing\Commerce\Acp\Support\EloquentOrderStore;
use Rushing\Commerce\Acp\Support\FixtureOfferResolver;
use Rushing\Commerce\Acp\Support\NullBeneficiaryResolver;
use Rushing\Commerce\Billing\BillComposer;
use Rushing\Commerce\Billing\ComponentRegistry;
use Rushing\Commerce\Billing\Pricing\PricingStrategyRegistry;
use Rushing\Commerce\Budget\BudgetGate;
use Rushing\Commerce\Contracts\MerchantResolver;
use Rushing\Commerce\Contracts\PaymentMethodResolver;
use Rushing\Commerce\Stripe\ConfigMerchantResolver;
use Rushing\Commerce\Stripe\ConfigStripeClients;
use Rushing\Commerce\Stripe\StripeClientFactory;
use Rushing\Commerce\Support\NullPaymentMethodResolver;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Registers the shared commerce engine. The money-in module (manager + `MoneyIn`
 * service) is bound as singletons; it is independent of any payment rail, so the
 * metering plane is usable with Cashier absent (a cost-only tenant like thingson.tv).
 *
 * Migrations ship as PUBLISH-ONLY spatie/laravel-package-tools stubs (the estate-wide
 * convention; mirrors laravel-beam-tenancy 755649a / laravel-beam-accounts 4f9ba78 /
 * tower 92919bb) — see {@see self::configurePackage()}. The 5 tables here (redemptions,
 * credit_entries, auto_reload_configs, auto_reload_attempts, the two ACP tables) key off
 * an opaque `party_id`/`payer_id` string, never a `tenant_id` FK — this engine is
 * deliberately tenant-blind (it doesn't depend on laravel-beam-tenancy at all). They are
 * UBIQUITOUS (central + every tenant — billing applies to both tenant-scoped and
 * central-level parties), so each publishes to the SINGLE `shared/…` destination declared
 * under `->hasMigrations([...])`, not a duplicated flat+tenant pair and not a host-side
 * placement decision: a central-only table would let tenant-context code silently fall
 * back through Postgres's `search_path` to the central copy (the same implicit-fallback
 * hazard a missing tenant `users` table produced elsewhere in this estate). beam-tenancy's
 * `registerSharedMigrationsPath()` is what runs that one host directory in both the central
 * `migrate` pass and Stancl's tenant pass; this package composes that mechanism purely by
 * publishing into the same `shared/` destination the host already registers (mirrors
 * beam-threads' own conversion, 36b2087). Since placement is now fixed rather than
 * host-decided, the old `commerce.register_migrations` config toggle (auto-load vs. don't)
 * stays dropped as redundant.
 *
 * The former `register()`/`boot()` bodies move to `packageRegistered()`/`packageBooted()` —
 * `configurePackage()` only configures, it doesn't replace the boot sequence.
 */
class CommerceServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-commerce')
            ->hasMigrations([
                'shared/create_commerce_redemptions_table',
                'shared/create_commerce_credit_entries_table',
                'shared/create_commerce_auto_reload_configs_table',
                'shared/create_commerce_auto_reload_attempts_table',
                'shared/create_commerce_acp_tables',
            ]);
    }

    public function packageRegistered(): void
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
        // Fulfillment beneficiary (issue 06): default resolves nothing, so out of the box a checkout
        // records the sale but grants nothing. A host binds its own resolver to grant value.
        $this->app->bind(BeneficiaryResolver::class, NullBeneficiaryResolver::class);
        $this->app->singleton(AgenticCheckout::class);

        // The ACP product-feed projector registry — the discovery half of the protocol.
        // A host registers a projector per catalog DTO to emit the agent-checkout feed shape.
        $this->app->singleton(AcpFeedRegistry::class);
    }

    public function packageBooted(): void
    {
        $this->describeRegistries();

        // The ACP protocol routes ship as brand-blind definitions; the host mounts
        // them inside its own public tenant group (prefix + tenancy middleware) by
        // calling Route::commerceAcpRoutes(). Mirrors the beam-commerce macro seam.
        Route::macro('commerceAcpRoutes', function (): void {
            Route::prefix(config('commerce.acp.route_root', 'agentic-commerce'))
                ->group(__DIR__.'/../routes/acp.php');
        });

        // The fake rail's hosted leg (BUY-02): its confirm page and billing portal stand-in. Brand-blind like the ACP
        // routes: a host mounts them where it wants (inside `web`), typically only off production.
        Route::macro('commerceFakeRailRoutes', function (): void {
            Route::prefix(config('commerce.fake.route_root', 'commerce/fake'))
                ->group(__DIR__.'/../routes/fake-rail.php');
        });

        // Engine-owned wallet funding: a Credit-topup Purchase funds the beneficiary's Wallet,
        // so every host gets top-up funding without wiring its own listener (the Stripe driver
        // only translates the provider event into the Purchase).
        Event::listen(Events\PurchaseCompleted::class, Listeners\FundWalletFromCreditTopup::class);

        // Under the fake rail no Stripe SDK request may leave the process: a money path that still calls Cashier or
        // stripe-php directly fails loudly instead of reaching api.stripe.com (purchase-walkthrough gap 7).
        if (config('commerce.driver') === 'fake' && class_exists(\Stripe\ApiRequestor::class)) {
            $guard = new Stripe\FakeRailStripeHttpClient;
            \Stripe\ApiRequestor::setHttpClient($guard);
            \Stripe\ApiRequestor::setStreamingHttpClient($guard);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/commerce.php' => $this->app->configPath('commerce.php'),
            ], 'commerce-config');

            $this->commands([
                Console\StripeListenCommand::class,
            ]);
        }

    }

    /**
     * Describe this package's four registries into the shared index — registry-kernel ticket 38.
     *
     * Declaring and indexing are two acts (21 D1), and an owner registers DOWN into the index from its
     * own boot(); the index never reaches up. Booted rather than registered because `describe()` forces
     * each singleton to construct, and `MoneyInManager`'s construction reads the container.
     */
    private function describeRegistries(): void {}
}
