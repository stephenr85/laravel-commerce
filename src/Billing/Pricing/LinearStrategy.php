<?php

namespace Rushing\Commerce\Billing\Pricing;

/**
 * A flat markup multiplier applied to the provider cost: charge = providerCost × markup.
 * The default and only launch strategy.
 */
class LinearStrategy implements PricingStrategy
{
    public function price(float $providerCostUsd, float $quantity, array $params): float
    {
        $markup = (float) ($params['markup'] ?? 1.0);

        return $providerCostUsd * $markup;
    }

    public function consumes(): string
    {
        return 'cost';
    }
}
