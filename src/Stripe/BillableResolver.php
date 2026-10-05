<?php

namespace Rushing\Commerce\Stripe;

use Illuminate\Database\Eloquent\Model;
use Rushing\Commerce\Data\Customer;

/**
 * The host's seam into its Cashier billable (purchase-walkthrough BUY-02 part B4): which model holds a commerce
 * Customer's Stripe customer (`stripe_id`) and cards. Provider-scoped, under `Stripe\`, because it returns a Cashier
 * Billable (ADR-0003: the neutral Contracts surface names no provider).
 */
interface BillableResolver
{
    /** A model using Laravel\Cashier\Billable for the commerce Customer; throws when the host knows no such party. */
    public function forCustomer(Customer $customer): Model;
}
