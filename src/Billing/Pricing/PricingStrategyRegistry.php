<?php

namespace Rushing\Commerce\Billing\Pricing;

use RuntimeException;

/**
 * Resolves Pricing Strategies by name. The seam is reserved for non-linear
 * strategies; only `linear` is registered at launch. A consumer registers its own.
 */
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
