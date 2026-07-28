<?php

namespace Rushing\Commerce\Acp\Support;

use Illuminate\Contracts\Config\Repository;
use Rushing\Commerce\Acp\Contracts\OfferResolver;
use Rushing\Commerce\Data\Money;
use Rushing\Commerce\Data\Offer;
use Rushing\Commerce\Data\Price;

/**
 * The tracer's `OfferResolver`: resolves a small set of fixture offers declared in
 * `commerce.acp.fixtures`, so the whole create -> charge -> record loop is
 * dogfoodable before a real catalog exists (slice 02 rebinds this to read the
 * schema.org/ACP feed). Ships one default offer so the package works out of the box.
 */
class FixtureOfferResolver implements OfferResolver
{
    public function __construct(private Repository $config) {}

    public function resolve(string $itemRef): ?Offer
    {
        $fixtures = (array) $this->config->get('commerce.acp.fixtures', []);
        $fixture = $fixtures[$itemRef] ?? null;

        if ($fixture === null) {
            return null;
        }

        $currency = (string) ($fixture['currency'] ?? $this->config->get('commerce.currency', 'USD'));

        return new Offer(
            slug: $itemRef,
            name: (string) ($fixture['name'] ?? $itemRef),
            price: new Price(Money::of((int) ($fixture['amount'] ?? 0), $currency)),
        );
    }
}
