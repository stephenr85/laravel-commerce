<?php

declare(strict_types=1);

namespace Rushing\Commerce\Contracts;

use Rushing\Commerce\Data\Merchant;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Data\Payment;

/**
 * Binds a recurring Order to a provider subscription and returns the neutral
 * Payment for the term. The host implements this by calling Laravel Cashier
 * directly (ADR-0001: we normalize the data, we do not wrap Cashier's surface),
 * which is why the package only declares the seam and ships no Cashier binding —
 * Cashier stays a composer `suggest`, so a cost-only tenant never pulls it.
 */
interface SubscriptionBinder
{
    public function bind(Order $order, Merchant $merchant): Payment;
}
