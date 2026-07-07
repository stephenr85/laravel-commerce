<?php

namespace Rushing\Commerce\Data;

use Rushing\LaravelDataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * The output of composing a Plan's components for a period: the ordered billing
 * lines and their summed total. The authoritative pricing/rollup math a Bill is
 * built from.
 */
class ComposedBill extends Data implements SchemaIdentity
{
    /**
     * @param  array<int, BillingLineItem>  $lineItems
     */
    public function __construct(
        #[DataCollectionOf(BillingLineItem::class)]
        public array $lineItems,
        public float $totalUsd,
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/composed-bill';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
