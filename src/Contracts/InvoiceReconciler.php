<?php

namespace Rushing\Commerce\Contracts;

use Rushing\Commerce\Data\InvoiceRef;

/**
 * A host reconciles a settled provider invoice back onto its own statement (Bill / Order):
 * given the neutral {@see InvoiceRef} a driver translated from the provider's paid event,
 * map `providerRef` → the local record and mark it paid. Idempotent by contract — a
 * redelivered event must be a no-op.
 */
interface InvoiceReconciler
{
    public function paid(InvoiceRef $ref): void;
}
