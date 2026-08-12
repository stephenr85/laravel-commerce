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
use Splicewire\Beam\Manifest\ManifestArity;
use Splicewire\Beam\Manifest\ManifestDescriptor;
use Splicewire\Beam\Manifest\ManifestIndex;
use Splicewire\Beam\Manifest\ManifestSeam;

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

            $this->commands([
                Console\StripeListenCommand::class,
            ]);
        }

        $this->describeManifests();
    }

    /**
     * Self-describe this engine's three remaining registry singletons into beam-core's `ManifestIndex` — the
     * index of indexes `splicewire:beam:manifests --json` renders (particle-doctrine-convergence, ticket 13).
     *
     * This package is beam-blind (it does not depend on laravel-beam, deliberately — it is tenant-blind and
     * rail-blind too), so both the class and the binding are guarded: outside a beam host, and in this
     * package's own test suite, nothing here runs. `AcpFeedRegistry` was already described by the host that
     * composes this engine, which is what made the package a member of the index and therefore held to
     * describing the rest — the three below were the gap that membership exposed.
     */
    protected function describeManifests(): void
    {
        if (! class_exists(ManifestIndex::class) || ! $this->app->bound(ManifestIndex::class)) {
            return;
        }

        $index = $this->app->make(ManifestIndex::class);
        $pkg = 'rushing/laravel-commerce';

        $index->describe(new ManifestDescriptor(
            name: 'MoneyInManager',
            of: 'money-in drivers (fake · stripe) — the rail a party collects through',
            // A Laravel `Manager` IS a registry at a seam, and the seam is its `extend()` hook: a host adds a
            // driver by resolving the shared manager and extending it, exactly as with any accumulator. The
            // config key only SELECTS among registered drivers; it does not register one, so ConfigSource
            // would point a host at the wrong file.
            seam: ManifestSeam::SingletonAccumulator,
            // PickOne: `commerce.driver` names the single driver a resolution returns, defaulting to `fake`.
            arity: ManifestArity::PickOne,
            registerHint: 'resolve the manager and call extend(\'yourdriver\', fn ($app) => new YourDriver) from your provider; select it with commerce.driver',
            where: MoneyInManager::class,
            package: $pkg, order: 60,
        ));

        $index->describe(new ManifestDescriptor(
            name: 'ComponentRegistry',
            of: 'billing components by type — the host vocabulary the bill composer resolves (the engine ships none)',
            seam: ManifestSeam::SingletonAccumulator,
            // PickOne, and a miss THROWS: a bill line naming a component the host never registered is a
            // configuration error, not something to paper over with a default component.
            arity: ManifestArity::PickOne,
            registerHint: 'resolve the singleton and register($type, fn () => new YourComponent) from your provider',
            where: ComponentRegistry::class,
            package: $pkg, order: 61,
        ));

        $index->describe(new ManifestDescriptor(
            name: 'PricingStrategyRegistry',
            of: 'pricing strategies by name; the engine pre-registers `linear` and reserves the seam for non-linear ones',
            seam: ManifestSeam::SingletonAccumulator,
            arity: ManifestArity::PickOne,
            registerHint: 'resolve the singleton and register(\'yourstrategy\', new YourStrategy) from your provider',
            where: PricingStrategyRegistry::class,
            package: $pkg, order: 62,
        ));
    }
}
