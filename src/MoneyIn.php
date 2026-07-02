<?php

declare(strict_types=1);

namespace Rushing\Commerce;

use Illuminate\Support\Str;
use Rushing\Commerce\Contracts\MoneyInDriver;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Data\Payment;
use Rushing\Commerce\Data\Purchase;
use Rushing\Commerce\Data\Receipt;
use Rushing\Commerce\Data\Refund;
use Rushing\Commerce\Events\PaymentRefunded;
use Rushing\Commerce\Events\PurchaseCompleted;

/**
 * The narrow money-in boundary: place an Order (capture a Payment, issue a
 * Receipt, complete a Purchase) and reverse a Payment. Provider mechanics live
 * behind the driver seam; this service only composes the neutral records and
 * fires the host-facing events.
 */
final class MoneyIn
{
    public function __construct(private MoneyInManager $manager) {}

    public function place(Order $order, ?string $driver = null, ?string $beneficiaryId = null): Purchase
    {
        $payment = $this->driver($driver)->pay($order);

        $receipt = new Receipt(
            id: (string) Str::uuid(),
            paymentId: $payment->id,
            orderId: $order->id,
            amount: $payment->amount,
        );

        $purchase = new Purchase(
            id: (string) Str::uuid(),
            orderId: $order->id,
            kind: $order->kind,
            payment: $payment,
            receipt: $receipt,
            beneficiaryId: $beneficiaryId,
        );

        PurchaseCompleted::dispatch($purchase);

        return $purchase;
    }

    public function refund(Payment $payment, ?string $driver = null): Refund
    {
        $refund = $this->driver($driver ?? $payment->driver)->refund($payment);

        PaymentRefunded::dispatch($refund);

        return $refund;
    }

    public function driver(?string $driver = null): MoneyInDriver
    {
        return $this->manager->driver($driver);
    }
}
