<?php

namespace Rushing\Commerce\Billing;

use Closure;
use Rushing\Commerce\Billing\Contracts\BillingComponent;
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
 * The consumer-populated map of component type => resolver. The package ships it
 * empty; each host registers its own concrete components (the platform's
 * `PerSeatFees` etc.), so no host vocabulary is hardcoded in the engine.
 *
 * ## An entry is the FACTORY, not the component
 *
 * The same split `GraphStoreManager` takes, and for the same reason: a component is built per bill
 * with a tenant bound into it (`fn () => new PerSeatFees($tenant)`), so storing a built component
 * would both construct eagerly and hand every bill the same instance. `resolve()` therefore returns
 * the factory — that is what "the one entry at this key" means here — and {@see make()} returns the
 * live component it builds. Those are two questions and one of the names belongs to the kernel now,
 * which is the identical rename `HandlerRegistry::resolve()` → `dispatch()` made in ticket 37.
 *
 * @implements Registry<Closure(): BillingComponent>
 */
#[IsRegistry(
    root: 'commerce.billing.components',
    entryType: Closure::class,
    onKeyDuplicate: OnKeyDuplicate::Supersede,
    populationRequirement: PopulationRequirement::Optional,
    description: 'billing components by type — the host vocabulary the bill composer resolves (the engine ships none). A miss THROWS: a bill line naming a component the host never registered is a configuration error, not something to paper over with a default component. Optional, not Required, because a host that composes no bills legitimately registers nothing — emptiness and a miss are different states. The host vocabulary is PascalCase (`PerSeatFees`) and lives in PERSISTED Plan data, so it cannot be rekeyed; `Key::fromClass()` derives the segment (`per-seat-fees`) and the caller keeps speaking its own spelling at every door.',
    order: 61,
)]
class ComponentRegistry implements Gated, Registry
{
    private BasicRegistry $entries;

    public function __construct()
    {
        $this->entries = BasicRegistry::for($this);
    }

    /**
     * Widened contravariantly from `register(string $type, callable|BillingComponent $factory): void`.
     * Both historical call shapes survive: a factory closure, and a bare component (still wrapped into
     * a factory, so `make()` keeps its one contract).
     */
    public function register(RegistryKey|string $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        if ($entry instanceof BillingComponent) {
            $component = $entry;
            $entry = fn (): BillingComponent => $component;
        }

        $this->entries->register($this->address($key), $entry, $by, $ability);

        return $this;
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->entries->has($this->address($key));
    }

    /**
     * The FACTORY registered under `$key` — see the class docblock. A miss throws the kernel's
     * `RegistryMiss`, which extends `RuntimeException`; this package's own miss was a bare
     * `RuntimeException`, so the change is invisible to every catch and to the package's own
     * `toThrow(RuntimeException::class)` assertion.
     */
    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->entries->resolve($this->address($key));
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->entries->tryResolve($this->address($key));
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->entries->matches($this->address($key));
    }

    public function keys(): array
    {
        return $this->entries->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->entries->unfiltered();
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->entries->authorizeWith($authorizer);

        return $this;
    }

    /**
     * Build the component registered under `$type` — the verb `resolve()` used to spell, renamed
     * because the kernel now owns that name and the two answer different questions.
     */
    public function make(RegistryKey|string $type): BillingComponent
    {
        return ($this->resolve($type))();
    }

    /**
     * The registered component types, in the registry's own spelling (`per-seat-fees`), not the host's
     * PascalCase. Nothing in the estate reads this method — grepped across
     * `~/Workspaces/php/packages/*` and the flagship — so reporting the keyspace's real spelling is
     * free, and it is the honest answer now that the keyspace is what the index holds.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return $this->entries->relativeKeys();
    }

    /**
     * A component type as a registry address.
     *
     * The host vocabulary is PascalCase and it is PERSISTED — a Plan row carries
     * `['type' => 'PerSeatFees']`, and `BillComposer` reads it straight out of that array — so the
     * spelling cannot be migrated. `Key`'s grammar is lowercase-only, and `Key::fromClass()` is the
     * kernel's explicit, opt-in derivation for exactly this: a component type is a class basename in
     * everything but PHP's eyes. It is idempotent over an already-legal key, so an absolute address
     * passes through untouched and both doors are one method.
     */
    private function address(RegistryKey|string $key): RegistryKey
    {
        return $key instanceof RegistryKey ? $key : Key::fromClass($key);
    }
}
