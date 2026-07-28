<?php

namespace Rushing\Commerce\Acp\Data;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * The pointer a completed checkout session carries back to the recorded minimal
 * order. It names the stored order and the Payment that settled it — never a
 * provider secret beyond the opaque `providerRef` the driver already exposes.
 */
class OrderRef extends Data implements SchemaIdentity
{
    public function __construct(
        public string $id,
        public string $checkoutSessionId,
        public string $paymentId,
        public ?string $providerRef = null,
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/acp/order-ref';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
