<?php

namespace Rushing\Commerce\Data;

use Rushing\LaravelDataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * One composed line on a cost-metering Bill, in fractional USD. Distinct from the
 * checkout {@see LineItem} (which carries Money minor units): generation costs run
 * sub-cent, so the metering line keeps a 6-decimal `amountUsd` rather than integer
 * minor units. See ADR-0002 on the two-line-item split.
 */
class BillingLineItem extends Data implements SchemaIdentity
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $componentType,
        public string $description,
        public float $amountUsd,
        public array $metadata = [],
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/billing-line-item';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
