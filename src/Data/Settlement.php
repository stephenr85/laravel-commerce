<?php

namespace Rushing\Commerce\Data;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * The record that closes the loop from "computed as owed" to "charged": it ties the
 * originating Bill (via `billReference`) to the Invoice that was collected and the
 * Purchase whose Payment carries provider state. The Bill remains authoritative; a
 * Settlement only records that the Invoice projected from it was paid.
 */
class Settlement extends Data implements SchemaIdentity
{
    public function __construct(
        public string $id,
        public Invoice $invoice,
        public Purchase $purchase,
        public ?string $billReference = null,
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/settlement';
    }

    /**
     * Version 2 (purchase-walkthrough BUY-02): gained its nested Invoice/Purchase, additive and nullable, so the v1 -> v2 step is the
     * identity on a stored payload. v1 stays frozen as written.
     */
    public static function schemaVersion(): int
    {
        return 2;
    }
}
