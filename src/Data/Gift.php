<?php

namespace Rushing\Commerce\Data;

use Rushing\LaravelDataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * A Purchase whose payer differs from its Beneficiary — the buyer pays; someone
 * else receives. Wraps *any* Purchase (including a Credit pack), and carries the
 * bearer code the Beneficiary redeems with.
 */
class Gift extends Data implements SchemaIdentity
{
    public function __construct(
        public Purchase $purchase,
        public Beneficiary $beneficiary,
        public string $redemptionCode,
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/gift';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
