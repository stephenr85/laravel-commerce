<?php

declare(strict_types=1);

namespace Rushing\Commerce\Tests\Support;

use Rushing\Commerce\Billing\BillingPeriod;
use Rushing\Commerce\Billing\Contracts\BillingComponent;
use Rushing\Commerce\Billing\Pricing\PricingStrategyRegistry;
use Rushing\Commerce\Data\BillingLineItem;

/**
 * A metered test component priced through a {@see PricingStrategyRegistry} — proves
 * the pricing seam composes without any host meter vocabulary in the engine.
 */
final class PerUnitComponent implements BillingComponent
{
    public function __construct(private PricingStrategyRegistry $strategies) {}

    public function calculate(BillingPeriod $period, array $config): BillingLineItem
    {
        $strategy = $this->strategies->resolve($config['strategy'] ?? 'linear');
        $providerCost = (float) ($config['provider_cost_usd'] ?? 0.0);
        $quantity = (float) ($config['quantity'] ?? 0.0);

        return new BillingLineItem(
            componentType: 'PerUnit',
            description: "Metered usage ({$period})",
            amountUsd: $strategy->price($providerCost, $quantity, $config),
            metadata: ['quantity' => $quantity],
        );
    }

    public static function configSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'markup' => ['type' => 'number', 'title' => 'Markup multiplier'],
            ],
        ];
    }
}
