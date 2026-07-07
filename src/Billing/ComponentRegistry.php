<?php

namespace Rushing\Commerce\Billing;

use RuntimeException;
use Rushing\Commerce\Billing\Contracts\BillingComponent;

/**
 * The consumer-populated map of component type => resolver. The package ships it
 * empty; each host registers its own concrete components (the platform's
 * `PerSeatFees` etc.), so no host vocabulary is hardcoded in the engine.
 */
class ComponentRegistry
{
    /** @var array<string, callable():BillingComponent> */
    private array $factories = [];

    /**
     * @param  (callable():BillingComponent)|BillingComponent  $factory
     */
    public function register(string $type, callable|BillingComponent $factory): void
    {
        $this->factories[$type] = $factory instanceof BillingComponent
            ? fn (): BillingComponent => $factory
            : $factory;
    }

    public function has(string $type): bool
    {
        return isset($this->factories[$type]);
    }

    public function resolve(string $type): BillingComponent
    {
        if (! isset($this->factories[$type])) {
            throw new RuntimeException("Unknown billing component type: {$type}");
        }

        return ($this->factories[$type])();
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->factories);
    }
}
