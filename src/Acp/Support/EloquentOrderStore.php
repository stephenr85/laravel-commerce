<?php

namespace Rushing\Commerce\Acp\Support;

use Illuminate\Support\Str;
use Rushing\Commerce\Acp\Contracts\OrderStore;
use Rushing\Commerce\Acp\Data\AgentProvenance;
use Rushing\Commerce\Acp\Data\CheckoutLineItem;
use Rushing\Commerce\Acp\Data\CheckoutSession;
use Rushing\Commerce\Acp\Data\OrderRef;
use Rushing\Commerce\Acp\Models\AcpOrder;
use Rushing\Commerce\Data\Payment;
use Rushing\Commerce\Data\Receipt;

/**
 * The default {@see OrderStore}: writes the minimal order + agent-provenance trace
 * to `commerce_acp_orders` and returns the {@see OrderRef} the session carries back.
 * Records catalog items only by their opaque refs. A richer substrate rebinds this.
 */
class EloquentOrderStore implements OrderStore
{
    public function record(
        CheckoutSession $session,
        Payment $payment,
        Receipt $receipt,
        AgentProvenance $provenance,
    ): OrderRef {
        $id = (string) Str::uuid();

        AcpOrder::query()->create([
            'id' => $id,
            'checkout_session_id' => $session->id,
            'currency' => $session->currency,
            'amount_minor' => $session->total->minorUnits,
            'item_refs' => array_map(
                fn (CheckoutLineItem $line): array => ['ref' => $line->itemRef, 'quantity' => $line->quantity],
                $session->lineItems,
            ),
            'payment_id' => $payment->id,
            'provider_ref' => $payment->providerRef,
            'driver' => $payment->driver,
            // The receipt recorded against the order (the buyer-facing proof of the charge).
            'receipt_id' => $receipt->id,
            'provenance' => $provenance->toArray(),
        ]);

        return new OrderRef(
            id: $id,
            checkoutSessionId: $session->id,
            paymentId: $payment->id,
            providerRef: $payment->providerRef,
        );
    }
}
