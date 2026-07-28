<?php

namespace Rushing\Commerce\Acp\Data;

use Rushing\Commerce\Data\Money;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * One line of an ACP checkout session. `itemRef` is the **opaque** catalog
 * reference the buyer's agent selected — the seam the (deferred) commerce
 * substrate resolves behind `OfferResolver`; the protocol layer never learns what
 * the ref *is* (a song, a gift, a credit pack). `amount` is the extended total
 * (unitAmount x quantity).
 */
class CheckoutLineItem extends Data implements SchemaIdentity
{
    public function __construct(
        public string $id,
        public string $itemRef,
        public string $name,
        public int $quantity,
        public Money $unitAmount,
        public Money $amount,
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/acp/checkout-line-item';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
