<?php

namespace Rushing\Commerce\Contracts;

use Rushing\Commerce\Data\PaymentMethodRef;

/**
 * The seam that answers "which saved card should an off-session auto-reload charge
 * for this party, and does one exist?" — scoped by (party, unit), host-bound
 * (ADR-0131). The charge *execution* is engine-side, but the *payment-method
 * identity* is host-side: the engine never persists or interprets a pm_/cus_ at
 * rest, it asks for one on demand through this contract at charge time.
 *
 * Distinct from {@see CustomerVault} (which is (Customer, Merchant)-scoped card
 * management): this expresses the host's auto-reload *policy* for picking a card
 * (a SetupIntent-saved default first, a subscription's Cashier default as fallback).
 * The engine binds a no-op default so it boots host-less; a host binds a real one.
 */
interface PaymentMethodResolver
{
    /**
     * The chargeable payment method for this party's auto-reload, or null if none.
     * Called at charge time to populate the off-session Order.
     */
    public function resolve(string $party, string $unit): ?PaymentMethodRef;

    /**
     * Cheap existence check backing a config's derived has_payment_method. Kept
     * separate from resolve() so the gate's hot path can hit a cached flag rather
     * than a provider/DB round-trip on every budget assessment.
     */
    public function has(string $party, string $unit): bool;
}
