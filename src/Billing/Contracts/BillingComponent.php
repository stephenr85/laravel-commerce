<?php

declare(strict_types=1);

namespace Rushing\Commerce\Billing\Contracts;

use Rushing\Commerce\Billing\BillingPeriod;
use Rushing\Commerce\Data\BillingLineItem;

/**
 * One priced element of a Plan — computes a single {@see BillingLineItem} for a
 * period from its config. Domain-agnostic: the host subject a concrete component
 * needs (a tenant's seats, a meter's tokens) is bound to the component instance
 * host-side; the engine never names it. The platform's `PerSeatFees`/`PerTokenFees`
 * stay in-app as implementations of this contract.
 */
interface BillingComponent
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function calculate(BillingPeriod $period, array $config): BillingLineItem;

    /**
     * JSON Schema describing this component's config keys, so an overrides editor
     * can render generically.
     *
     * @return array<string, mixed>
     */
    public static function configSchema(): array;
}
