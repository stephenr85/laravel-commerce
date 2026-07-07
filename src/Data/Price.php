<?php

namespace Rushing\Commerce\Data;

use Rushing\Commerce\Enums\Cadence;
use Rushing\LaravelDataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * The Money charged for an Offer or one of a Plan's components, with the Cadence at
 * which it recurs. Resolved by a pricing strategy upstream; here it is the settled
 * amount.
 */
class Price extends Data implements SchemaIdentity
{
    public function __construct(
        public Money $amount,
        public Cadence $cadence = Cadence::OneTime,
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/price';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
