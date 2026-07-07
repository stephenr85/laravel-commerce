<?php

namespace Rushing\Commerce\Billing;

use Rushing\Commerce\Data\BillingLineItem;
use Rushing\Commerce\Data\ComposedBill;

/**
 * The extracted composition loop: run an ordered set of a Plan's components for a
 * period and sum their lines into a {@see ComposedBill}. Host orchestration (which
 * subscription, whether to persist a Bill, tenancy) stays in the consumer — this
 * owns only the domain-agnostic compose-and-total math.
 */
class BillComposer
{
    public function __construct(private ComponentRegistry $registry) {}

    /**
     * @param  array<int, array{type: string, config?: array<string, mixed>}>  $components
     * @param  array<string, array<string, mixed>>  $overrides  keyed by "Type" or "Type.meter"
     */
    public function compose(array $components, BillingPeriod $period, array $overrides = []): ComposedBill
    {
        $lineItems = [];

        foreach ($components as $component) {
            $type = $component['type'];
            $baseConfig = $component['config'] ?? [];

            // A Plan may carry multiple instances of a component (one per meter), so an
            // override can target a single instance via "Type.meter"; a plain "Type"
            // key still applies for single-instance components.
            $meterKey = isset($baseConfig['meter']) ? "{$type}.{$baseConfig['meter']}" : null;
            $override = ($meterKey !== null ? ($overrides[$meterKey] ?? null) : null)
                ?? $overrides[$type]
                ?? [];

            $config = array_merge($baseConfig, $override);

            $lineItems[] = $this->registry->resolve($type)->calculate($period, $config);
        }

        $total = array_sum(array_map(fn (BillingLineItem $item): float => $item->amountUsd, $lineItems));

        return new ComposedBill(lineItems: $lineItems, totalUsd: $total);
    }
}
