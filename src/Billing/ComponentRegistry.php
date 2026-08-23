<?php

namespace Rushing\Commerce\Billing;

use RuntimeException;
use Rushing\Commerce\Billing\Contracts\BillingComponent;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Optionality;
use Rushing\Popcorn\Registries\RegistryArity;

/**
 * The consumer-populated map of component type => resolver. The package ships it
 * empty; each host registers its own concrete components (the platform's
 * `PerSeatFees` etc.), so no host vocabulary is hardcoded in the engine.
 */
#[IsRegistry(
    root: 'commerce.billing.components',
    of: 'billing components by type — the host vocabulary the bill composer resolves (the engine ships none)',
    arity: RegistryArity::PickOne,
    entryType: BillingComponent::class,
    onDuplicate: OnDuplicate::Supersede,
    optionality: Optionality::Optional,
    note: 'A miss THROWS: a bill line naming a component the host never registered is a configuration '
        .'error, not something to paper over with a default component. Optional, not Required, because '
        .'a host that composes no bills legitimately registers nothing — emptiness and a miss are '
        .'different states.',
    order: 61,
)]
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
