<?php

namespace Rushing\Commerce;

use Illuminate\Support\Manager;
use Rushing\Commerce\Drivers\FakeDriver;
use Rushing\Commerce\Drivers\StripeDriver;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;

/**
 * Resolves the money-in driver from per-tenant config (`commerce.driver`). The
 * `fake` driver records the same DTOs with no network; `stripe` collects real
 * money via the Stripe SDK on the billing party's own account. Both are resolved
 * from the container so their seams (client factory, merchant resolver, optional
 * subscription binder) are host-overridable.
 */
#[IsRegistry(
    root: 'commerce.money-in.drivers',
    of: 'money-in drivers (fake · stripe) — the rail a party collects through',
    arity: RegistryArity::PickOne,
    onDuplicate: OnDuplicate::Supersede,
    note: 'A foreign-base registry: it extends Laravel\'s Manager, so it can extend nothing of ours — '
        .'which is the deciding case for Registry being an interface rather than a base class. The seam '
        .'is `extend()`; `commerce.driver` only SELECTS among registered drivers, it never registers one. '
        .'Manager has no un-register: forgetDrivers() clears memoized instances, $customCreators survive.',
    order: 60,
)]
class MoneyInManager extends Manager
{
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
}
