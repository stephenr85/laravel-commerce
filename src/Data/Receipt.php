<?php

namespace Rushing\Commerce\Data;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * The Customer-facing proof of a completed Payment.
 */
class Receipt extends Data implements SchemaIdentity
{
    public function __construct(
        public string $id,
        public string $paymentId,
        public string $orderId,
        public Money $amount,
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/receipt';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
