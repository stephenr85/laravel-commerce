<?php

namespace Rushing\Commerce\Billing\Pricing;

/**
 * Turns a meter's recorded usage into a charge. A strategy is selected per priced
 * component so each meter can price itself by its own rule. `linear` is the only
 * strategy that ships; non-linear rules (scaled/tiered) are siblings added when a
 * real pricing need arrives.
 */
interface PricingStrategy
{
    /**
     * @param  float  $providerCostUsd  the period's recorded provider cost for the meter
     * @param  float  $quantity  the period's recorded quantity in the meter's native unit
     * @param  array<string, mixed>  $params  strategy-specific config (e.g. markup)
     */
    public function price(float $providerCostUsd, float $quantity, array $params): float;

    /** Which input this strategy consumes: 'cost' or 'quantity'. */
    public function consumes(): string;
}
