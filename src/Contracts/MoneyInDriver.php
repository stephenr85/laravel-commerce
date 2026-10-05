<?php

namespace Rushing\Commerce\Contracts;

use Rushing\Commerce\Data\Customer;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Data\Payment;
use Rushing\Commerce\Data\PaymentAction;
use Rushing\Commerce\Data\Refund;

/**
 * The seam every money-in provider sits behind. A driver captures money for an
 * Order and reverses a Payment; it normalizes provider mechanics into our DTOs.
 * We do not wrap a provider's own method surface (ADR-0001) — a driver simply
 * returns the neutral records.
 */
interface MoneyInDriver
{
    public function name(): string;

    public function pay(Order $order): Payment;

    public function refund(Payment $payment): Refund;

    /**
     * The customer's self-service billing page (purchase-walkthrough BUY-02: the portal goes through the rail). A
     * redirect {@see PaymentAction}; a rail that collects nothing throws.
     */
    public function manage(Customer $customer, string $returnUrl): PaymentAction;
}
