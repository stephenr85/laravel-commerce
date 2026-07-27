<?php

namespace Rushing\Commerce\Data;

use Rushing\Commerce\Contracts\PaymentMethodResolver;

/**
 * An opaque handle to a party's chargeable card, resolved on demand by a host-bound
 * {@see PaymentMethodResolver}. The engine passes these
 * tokens *through* to the driver at charge time and never persists or interprets
 * them (ADR-0131) — bundling the customer and payment-method keeps all host-specific
 * provider identity in the resolver and out of the driver. Not a spatie Data class:
 * it is a transient charge-time value, never serialized into a schema at rest.
 */
final class PaymentMethodRef
{
    public function __construct(
        public readonly string $customer,        // e.g. cus_… — which provider customer is this party
        public readonly string $paymentMethod,   // e.g. pm_…
        public readonly string $source,          // setup_intent | subscription
    ) {}
}
