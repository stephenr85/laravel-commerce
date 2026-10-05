<?php

namespace Rushing\Commerce\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Rushing\Commerce\Data\Customer;

/**
 * A customer's saved cards changed (a new default, a card removed). Listeners drop what they cached about the cards,
 * e.g. beam-commerce's auto-reload "has a card" flag (purchase-walkthrough BUY-02 part B4).
 */
class PaymentMethodsChanged
{
    use Dispatchable;

    public function __construct(public Customer $customer) {}
}
