<?php

namespace Rushing\Commerce\Drivers;

use Rushing\Commerce\Contracts\MoneyInDriver;
use Rushing\Commerce\Data\Customer;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Data\Payment;
use Rushing\Commerce\Data\PaymentAction;
use Rushing\Commerce\Data\Refund;
use Rushing\Commerce\Exceptions\MoneyInUnavailable;

/**
 * The rail of a host that collects no money (purchase-walkthrough BUY-4: custody `none`). It refuses every verb,
 * loudly: custody `none` renders no purchase affordance, so a call that reaches it is a caller's defect, and a
 * refusal is safer than a Payment that could be mistaken for an outcome. Unlike `fake` it is legal in production.
 */
class NoneDriver implements MoneyInDriver
{
    public function name(): string
    {
        return 'none';
    }

    public function pay(Order $order): Payment
    {
        throw MoneyInUnavailable::make('take a payment');
    }

    public function refund(Payment $payment): Refund
    {
        throw MoneyInUnavailable::make('refund a payment');
    }

    public function manage(Customer $customer, string $returnUrl): PaymentAction
    {
        throw MoneyInUnavailable::make('open a billing portal');
    }
}
