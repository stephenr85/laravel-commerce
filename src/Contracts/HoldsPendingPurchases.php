<?php

namespace Rushing\Commerce\Contracts;

use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Data\Purchase;

/**
 * A rail that settles a hosted leg itself rather than through a provider webhook (the `fake` rail's local confirm page)
 * keeps the {@see Purchase} {@see \Rushing\Commerce\MoneyIn::place()} built, so settling it dispatches exactly the
 * PurchaseCompleted a webhook translator would.
 */
interface HoldsPendingPurchases
{
    public function holdPending(Purchase $purchase, Order $order): void;
}
