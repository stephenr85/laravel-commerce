<?php

namespace Rushing\Commerce\Support;

use Rushing\Commerce\Contracts\PaymentMethodResolver;
use Rushing\Commerce\Data\PaymentMethodRef;

/**
 * The engine's default resolver: no host bound, so no party has a chargeable card.
 * Lets the engine boot and read configs host-less (has() => false, resolve() => null,
 * so a config simply reads as needs_payment_method and never fires). A host binds a
 * real resolver over its own card store to lift this.
 */
class NullPaymentMethodResolver implements PaymentMethodResolver
{
    public function resolve(string $party, string $unit): ?PaymentMethodRef
    {
        return null;
    }

    public function has(string $party, string $unit): bool
    {
        return false;
    }
}
