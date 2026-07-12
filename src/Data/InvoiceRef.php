<?php

namespace Rushing\Commerce\Data;

use Rushing\Commerce\Contracts\InvoiceReconciler;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * A neutral reference to a provider invoice, carried inbound when the provider reports
 * it settled. A host {@see InvoiceReconciler} maps `providerRef`
 * back to whatever it issued the invoice against (its Bill / Order) and marks it paid. The
 * engine never learns the host's statement model.
 */
class InvoiceRef extends Data implements SchemaIdentity
{
    public function __construct(
        public string $providerRef,
        public bool $paid = true,
        public ?string $billReference = null,
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/invoice-ref';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
