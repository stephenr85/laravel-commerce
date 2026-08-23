<?php

namespace Rushing\Commerce\Billing\Pricing;

use RuntimeException;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;

/**
 * Resolves Pricing Strategies by name. The seam is reserved for non-linear
 * strategies; only `linear` is registered at launch. A consumer registers its own.
 */
#[IsRegistry(
    root: 'commerce.billing.pricing-strategies',
    of: 'pricing strategies by name; the engine pre-registers `linear` and reserves the seam for non-linear ones',
    arity: RegistryArity::PickOne,
    onDuplicate: OnDuplicate::Supersede,
    note: 'Constructor-seeded AND registered into: `linear` ships pre-registered, so a host overriding it '
        .'by name is doing so deliberately and Supersede is the honest policy.',
    order: 62,
)]
class PricingStrategyRegistry
{
    /** @var array<string, PricingStrategy> */
    private array $strategies = [];

    public function __construct()
    {
        $this->register('linear', new LinearStrategy);
    }

    public function register(string $name, PricingStrategy $strategy): void
    {
        $this->strategies[$name] = $strategy;
    }

    public function resolve(string $name): PricingStrategy
    {
        if (! isset($this->strategies[$name])) {
            throw new RuntimeException("Unknown pricing strategy: {$name}");
        }

        return $this->strategies[$name];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->strategies);
    }
}
