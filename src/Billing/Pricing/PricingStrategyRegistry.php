<?php

namespace Rushing\Commerce\Billing\Pricing;

use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * Resolves Pricing Strategies by name. The seam is reserved for non-linear
 * strategies; only `linear` is registered at launch. A consumer registers its own.
 *
 * @implements Registry<PricingStrategy>
 */
#[IsRegistry(
    root: 'commerce.billing.pricing-strategies',
    of: 'pricing strategies by name; the engine pre-registers `linear` and reserves the seam for non-linear ones',
    arity: RegistryArity::PickOne,
    entryType: PricingStrategy::class,
    onDuplicate: OnDuplicate::Supersede,
    note: 'Constructor-seeded AND registered into: `linear` ships pre-registered, so a host overriding it '
        .'by name is doing so deliberately and Supersede is the honest policy. The seed goes through '
        .'this class\'s own register(), which is safe here because register() does nothing but store — '
        .'the RealmRegistry case (37) where a constructor seed tripped a flag set inside register() has '
        .'no analogue.',
    order: 62,
)]
class PricingStrategyRegistry implements Gated, Registry
{
    private BasicRegistry $entries;

    public function __construct()
    {
        $this->entries = BasicRegistry::for($this);

        $this->register('linear', new LinearStrategy, by: self::class);
    }

    /**
     * Widened contravariantly from `register(string $name, PricingStrategy $strategy): void`. PHP
     * forbids narrowing a parameter, so both slots widen and every historical two-argument call —
     * `register('tiered', new TieredStrategy)` — keeps working unchanged.
     *
     * The return type moves from `void` to `static` because the contract requires it; no caller in the
     * estate reads it, so nothing breaks and chaining becomes available.
     */
    public function register(RegistryKey|string $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        $this->entries->register($key, $entry, $by, $ability);

        return $this;
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->entries->has($key);
    }

    /**
     * A miss throws, as it always did. The class changes from this package's own `RuntimeException` to
     * the kernel's `RegistryMiss` — which EXTENDS `RuntimeException`, so the package's own
     * `toThrow(RuntimeException::class)` assertion and every host catch survive untouched. That
     * compatibility is why the exception change is taken here and declined on `AcpFeedRegistry`, where
     * the asserted type is `InvalidArgumentException` and no such relation exists.
     *
     * The strategy name arrives off a Plan's stored component config (`$config['strategy']`), so it is
     * persisted host data rather than request text; an unparseable one is a configuration error and
     * failing loudly is the same call `HandlerRegistry` makes for a config-sourced handle.
     */
    public function resolve(RegistryKey|string $key): PricingStrategy
    {
        return $this->entries->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->entries->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->entries->matches($key);
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
     * The caller-facing enumeration, still the bare names a host registered. Built from
     * `relativeKeys()` rather than `keys()`: keys go relative in and absolute out (20 D2), and this
     * list is read back by callers who speak the relative spelling.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return $this->entries->relativeKeys();
    }
}
