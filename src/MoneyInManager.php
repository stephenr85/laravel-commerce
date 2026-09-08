<?php

namespace Rushing\Commerce;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Manager;
use InvalidArgumentException;
use Rushing\Commerce\Drivers\FakeDriver;
use Rushing\Commerce\Drivers\StripeDriver;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\PopulationRequirement;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * Resolves the money-in driver from per-tenant config (`commerce.driver`). The
 * `fake` driver records the same DTOs with no network; `stripe` collects real
 * money via the Stripe SDK on the billing party's own account. Both are resolved
 * from the container so their seams (client factory, merchant resolver, optional
 * subscription binder) are host-overridable.
 *
 * ## Archetype **e**, and the recipe is graphine's — cited, not re-derived
 *
 * `Rushing\Graphine\Laravel\GraphStoreManager` is the landed shape (registry-kernel tickets 10/28) and
 * this class follows it line for line: `extends Manager` keeps `__call` proxying, memoisation and
 * `getDefaultDriver()`; a held {@see BasicRegistry} keeps the keyspace; `register()` records the key
 * BEFORE delegating to `extend()`, which is what makes `keys()` honest without reflecting into the
 * parent's protected `$customCreators`.
 *
 * **An entry is the FACTORY, not the driver.** `resolve('stripe')` hands back the closure and
 * `driver('stripe')` hands back the built `MoneyInDriver`. Enumeration must not construct — a
 * `keys()` that booted every driver would instantiate the Stripe SDK to answer "what rails exist"
 * (ticket 10 D5).
 *
 * **The two shipped drivers are registered, not left to convention.** `Manager` would find
 * `createFakeDriver()` / `createStripeDriver()` by name, but nothing can enumerate them that way
 * without reflection, so a registry that reported only what a host `extend()`ed would report an empty
 * root while two drivers worked. That is the archetype-a promise failing quietly, and registering the
 * pair here is what stops it.
 *
 * **`driver(null)` stays off the contract** (ticket 10 D4). The defaulted read is a `Manager` concept
 * the kernel does not have, and `commerce.driver` only SELECTS among registered drivers — it never
 * registers one.
 *
 * @method \Rushing\Commerce\Contracts\MoneyInDriver driver(?string $driver = null)
 */
#[IsRegistry(
    root: 'commerce.money-in.drivers',
    entryType: Closure::class,
    onKeyDuplicate: OnKeyDuplicate::Supersede,
    populationRequirement: PopulationRequirement::Optional,
    description: 'money-in drivers (fake · stripe) — the rail a party collects through, as the factories that build them. A foreign-base registry: it extends Laravel\'s Manager, so it can extend nothing of ours — which is the deciding case for Registry being an interface rather than a base class. The seam is `extend()`; `commerce.driver` only SELECTS among registered drivers, it never registers one. Manager has no un-register: forgetDrivers() clears memoized instances, $customCreators survive — and so does this keyspace, deliberately, because a forgotten instance is not a forgotten registration.',
    order: 60,
)]
class MoneyInManager extends Manager implements Gated, Registry
{
    private BasicRegistry $rails;

    public function __construct(Container $container)
    {
        parent::__construct($container);

        // NOT `$drivers`: `Manager` already declares a protected `$drivers` holding RESOLVED driver
        // instances, and a private field of that name is a fatal redeclaration. The foreign base owns
        // the obvious noun, which is the archetype-e tax graphine's `$stores` paid without noticing.
        $this->rails = BasicRegistry::for($this);

        // The two shipped rails are entries like any other. Registering them rather than leaning on
        // Manager's create*Driver() convention is what lets keys() report them at all.
        $this->register('fake', fn (): FakeDriver => $this->createFakeDriver(), by: self::class);
        $this->register('stripe', fn (): StripeDriver => $this->createStripeDriver(), by: self::class);
    }

    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('commerce.driver', 'fake');
    }

    public function createFakeDriver(): FakeDriver
    {
        return new FakeDriver;
    }

    public function createStripeDriver(): StripeDriver
    {
        return $this->container->make(StripeDriver::class);
    }

    /**
     * Register a driver factory under `$key`, then hand the same closure to `Manager::extend()`.
     *
     * The key goes in relative and comes back absolute (20 D2), so `register('kg', …)` stores
     * `commerce.money-in.drivers.kg` while `extend()` — which speaks `Manager`'s bare driver names —
     * is given `kg`.
     */
    public function register(RegistryKey|string $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        if (! $entry instanceof Closure) {
            throw new InvalidArgumentException(sprintf(
                'A `%s` entry must be a Closure building a money-in driver; got %s for `%s`. '
                    .'`Manager::extend()` takes a factory, and registering a built driver would '
                    .'construct the Stripe client at boot.',
                self::class,
                get_debug_type($entry),
                (string) $key,
            ));
        }

        $this->rails->register($key, $entry, $by, $ability);

        parent::extend($this->driverName($key), $entry);

        return $this;
    }

    /**
     * `Manager::extend()`, routed through {@see register()} so the keyspace never misses a write. Every
     * host that already says `MoneyIn::extend('adyen', …)` keeps working and becomes visible.
     */
    public function extend($driver, Closure $callback): static
    {
        return $this->register($driver, $callback, by: 'extend');
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->rails->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->rails->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->rails->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->rails->matches($key);
    }

    public function keys(): array
    {
        return $this->rails->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->rails->unfiltered();
    }

    /**
     * {@see Gated} — implemented even though no driver declares an `ability`. An ungated entry
     * short-circuits before the authorizer is consulted, so this costs nothing today, and a registry
     * that cannot receive the authorizer is one a host can never close (09 D7).
     */
    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->rails->authorizeWith($authorizer);

        return $this;
    }

    /**
     * A registry key as the bare driver name `Manager` indexes by. Strips the declared root where the
     * caller spelled an absolute key, and refuses anything deeper than one segment: a money-in driver
     * name is a `Manager` driver name, and `commerce.money-in.drivers.stripe.eu` would register a
     * keyspace entry `driver()` could never reach.
     */
    private function driverName(RegistryKey|string $key): string
    {
        $segments = ($key instanceof RegistryKey ? $key : Key::parse($key))->segments();
        $root = $this->rails->declaration()->rootKey()->segments();

        if (array_slice($segments, 0, count($root)) === $root) {
            $segments = array_slice($segments, count($root));
        }

        if (count($segments) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'A money-in driver key is one segment under `%s` — a `Manager` driver name. Got `%s`.',
                implode('.', $root),
                (string) $key,
            ));
        }

        return $segments[0];
    }
}
