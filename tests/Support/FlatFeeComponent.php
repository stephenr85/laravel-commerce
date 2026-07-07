<?php

namespace Rushing\Commerce\Tests\Support;

use Rushing\Commerce\Billing\BillingPeriod;
use Rushing\Commerce\Billing\Contracts\BillingComponent;
use Rushing\Commerce\Data\BillingLineItem;

/**
 * A domain-agnostic test component: a flat fee read straight from config. Stands in
 * for a host's concrete component (the platform's SubscriptionFee) without dragging
 * any host vocabulary into the package.
 */
class FlatFeeComponent implements BillingComponent
{
    public function calculate(BillingPeriod $period, array $config): BillingLineItem
    {
        return new BillingLineItem(
            componentType: 'FlatFee',
            description: "Flat fee ({$period})",
            amountUsd: (float) ($config['amount_usd'] ?? 0.0),
        );
    }

    public static function configSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'amount_usd' => ['type' => 'number', 'title' => 'Flat amount (USD)'],
            ],
        ];
    }
}
