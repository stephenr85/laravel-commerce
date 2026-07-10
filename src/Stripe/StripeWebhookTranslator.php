<?php

namespace Rushing\Commerce\Stripe;

use Illuminate\Support\Str;
use Rushing\Commerce\Data\InvoiceRef;
use Rushing\Commerce\Data\Money;
use Rushing\Commerce\Data\Payment;
use Rushing\Commerce\Data\Purchase;
use Rushing\Commerce\Data\Receipt;
use Rushing\Commerce\Data\SubscriptionState;
use Rushing\Commerce\Enums\PaymentStatus;
use Rushing\Commerce\Enums\PurchaseKind;

/**
 * Translates raw Stripe webhook payloads into the engine's neutral inbound DTOs — the one
 * place that touches Stripe's event shape. Every method takes a decoded payload **array**,
 * not an event object, so both hosts feed it the same way: splicewire-app from Cashier's
 * `WebhookReceived`, a satellite from its own per-party `StripeWebhookReceived`. Each returns
 * null when the payload isn't the event it handles, so a host can pass every event through.
 */
class StripeWebhookTranslator
{
    /** Stripe subscription statuses that still grant access (past_due is a grace window). */
    private const ACTIVE_STATUSES = ['active', 'trialing', 'past_due'];

    /**
     * `customer.subscription.*` → neutral {@see SubscriptionState}, or null if not a
     * subscription lifecycle event / missing customer or price.
     *
     * @param  array<string, mixed>  $payload
     */
    public function subscription(array $payload): ?SubscriptionState
    {
        $type = $payload['type'] ?? null;

        if (! in_array($type, [
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
        ], true)) {
            return null;
        }

        $object = $payload['data']['object'] ?? [];
        $customerRef = $object['customer'] ?? null;
        $priceRef = $object['items']['data'][0]['price']['id'] ?? null;

        if ($customerRef === null || $priceRef === null) {
            return null;
        }

        $status = (string) ($object['status'] ?? '');
        $active = $type !== 'customer.subscription.deleted'
            && in_array($status, self::ACTIVE_STATUSES, true);

        return new SubscriptionState(
            customerRef: $customerRef,
            priceRef: $priceRef,
            status: $status,
            active: $active,
            currentPeriodEnd: isset($object['current_period_end']) ? (int) $object['current_period_end'] : null,
        );
    }

    /**
     * `invoice.paid` → neutral {@see InvoiceRef}, or null otherwise.
     *
     * @param  array<string, mixed>  $payload
     */
    public function invoicePaid(array $payload): ?InvoiceRef
    {
        if (($payload['type'] ?? null) !== 'invoice.paid') {
            return null;
        }

        $providerRef = $payload['data']['object']['id'] ?? null;

        return $providerRef === null ? null : new InvoiceRef(providerRef: $providerRef, paid: true);
    }

    /**
     * A settled `checkout.session.completed` credit top-up → a completed {@see Purchase}
     * (kind {@see PurchaseKind::CreditTopup}) the host can dispatch as a `PurchaseCompleted`
     * so the engine's wallet funding runs. Null unless it is a paid credit-topup session.
     * The billing party rides in `party_ref` metadata; the paid amount is the session total,
     * and the session id is the Payment's `providerRef` (the wallet's idempotency key).
     *
     * @param  array<string, mixed>  $payload
     */
    public function creditTopup(array $payload): ?Purchase
    {
        if (($payload['type'] ?? null) !== 'checkout.session.completed') {
            return null;
        }

        $session = $payload['data']['object'] ?? [];
        $metadata = $session['metadata'] ?? [];

        if (($metadata['kind'] ?? null) !== 'credit_topup'
            || ($session['payment_status'] ?? null) !== 'paid') {
            return null;
        }

        $party = $metadata['party_ref'] ?? null;
        $sessionId = $session['id'] ?? null;

        if ($party === null || $sessionId === null || ! isset($session['amount_total'])) {
            return null;
        }

        $amount = Money::of((int) $session['amount_total'], strtoupper((string) ($session['currency'] ?? 'usd')));
        $orderId = (string) Str::uuid();

        $payment = new Payment(
            id: (string) Str::uuid(),
            orderId: $orderId,
            amount: $amount,
            status: PaymentStatus::Succeeded,
            driver: 'stripe',
            providerRef: $sessionId,
        );

        return new Purchase(
            id: (string) Str::uuid(),
            orderId: $orderId,
            kind: PurchaseKind::CreditTopup,
            payment: $payment,
            receipt: new Receipt(
                id: (string) Str::uuid(),
                paymentId: $payment->id,
                orderId: $orderId,
                amount: $amount,
            ),
            beneficiaryId: $party,
        );
    }
}
