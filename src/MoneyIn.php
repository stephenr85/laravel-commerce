<?php

namespace Rushing\Commerce;

use Illuminate\Support\Str;
use Rushing\Commerce\Contracts\HoldsPendingPurchases;
use Rushing\Commerce\Contracts\MoneyInDriver;
use Rushing\Commerce\Data\Customer;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Data\Payment;
use Rushing\Commerce\Data\PaymentAction;
use Rushing\Commerce\Data\Purchase;
use Rushing\Commerce\Data\Receipt;
use Rushing\Commerce\Data\Refund;
use Rushing\Commerce\Enums\CustodyPosture;
use Rushing\Commerce\Events\PaymentRefunded;
use Rushing\Commerce\Events\PurchaseCompleted;

/**
 * The narrow money-in boundary: place an Order (capture a Payment, issue a
 * Receipt, complete a Purchase) and reverse a Payment. Provider mechanics live
 * behind the driver seam; this service only composes the neutral records and
 * fires the host-facing events.
 */
class MoneyIn
{
    public function __construct(private MoneyInManager $manager) {}

    /**
     * @param  array<string, mixed>  $grant  opaque fulfillment bag carried onto the Purchase (issue 07)
     */
    public function place(Order $order, ?string $driver = null, ?string $beneficiaryId = null, array $grant = []): Purchase
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
            grant: $grant,
        );

        // Only a captured payment completes a purchase — a decline or an unresolved
        // step-up (an off-session card that failed or needs authentication) must never
        // fund a Wallet. The caller inspects the returned Purchase's payment status.
        if ($payment->succeeded()) {
            PurchaseCompleted::dispatch($purchase);
        } elseif ($payment->action !== null && ($rail = $this->driver($driver)) instanceof HoldsPendingPurchases) {
            // A hosted leg the rail settles itself (the fake's confirm page) keeps the Purchase this call built, so the
            // settlement dispatches the same PurchaseCompleted (BUY-02, C-11).
            $rail->holdPending($purchase, $order);
        }

        return $purchase;
    }

    public function refund(Payment $payment, ?string $driver = null): Refund
    {
        $refund = $this->driver($driver ?? $payment->driver)->refund($payment);

        PaymentRefunded::dispatch($refund);

        return $refund;
    }

    /**
     * The customer's self-service billing page through the rail (BUY-02: the portal goes through `manage`, never a direct
     * provider call). A redirect action; the `none` rail throws.
     */
    public function manage(Customer $customer, string $returnUrl, ?string $driver = null): PaymentAction
    {
        return $this->driver($driver)->manage($customer, $returnUrl);
    }

    /**
     * Who holds the money here (BUY-4). Read ONLY from `commerce.custody`; the rail never changes it. Undeclared keeps
     * today's behaviour, purchases offered through hosted legs; a host that collects nothing declares `none`.
     */
    public function posture(): CustodyPosture
    {
        return CustodyPosture::tryFrom((string) config('commerce.custody')) ?? CustodyPosture::Hosted;
    }

    public function driver(?string $driver = null): MoneyInDriver
    {
        return $this->manager->driver($driver);
    }
}
