<?php

declare(strict_types=1);

namespace Rushing\Commerce;

use Illuminate\Support\Manager;
use Rushing\Commerce\Drivers\FakeDriver;
use Rushing\Commerce\Drivers\StripeDriver;

/**
 * Resolves the money-in driver from per-tenant config (`commerce.driver`). The
 * `fake` driver records the same DTOs with no network; `stripe` collects real
 * money via the Stripe SDK on the billing party's own account. Both are resolved
 * from the container so their seams (client factory, merchant resolver, optional
 * subscription binder) are host-overridable.
 */
final class MoneyInManager extends Manager
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
