<?php

namespace Rushing\Commerce\Contracts;

use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Data\Payment;
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
}
