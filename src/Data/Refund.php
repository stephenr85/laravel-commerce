<?php

namespace Rushing\Commerce\Data;

use Rushing\LaravelDataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * A reversal of a Payment. Distinct from a provider-initiated chargeback.
 */
class Refund extends Data implements SchemaIdentity
{
    public function __construct(
        public string $id,
        public string $paymentId,
        public Money $amount,
        public string $driver,
        public ?string $providerRef = null,
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/refund';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
