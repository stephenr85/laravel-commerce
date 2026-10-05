<?php

namespace Rushing\Commerce\Data;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
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

    /**
     * Version 2 (purchase-walkthrough BUY-02): gained its nested Order/Payment/Customer, additive and nullable, so the v1 -> v2 step is the
     * identity on a stored payload. v1 stays frozen as written.
     */
    public static function schemaVersion(): int
    {
        return 2;
    }
}
