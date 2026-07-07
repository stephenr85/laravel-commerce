<?php

namespace Rushing\Commerce\Data;

use Rushing\LaravelDataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * One priced line within an Order (or a Bill), carrying its Money subtotal.
 * `amount` is the extended total (unitPrice x quantity).
 */
class LineItem extends Data implements SchemaIdentity
{
    public function __construct(
        public string $description,
        public int $quantity,
        public Money $unitPrice,
        public Money $amount,
    ) {}

    public static function for(string $description, Money $unitPrice, int $quantity = 1): self
    {
        return new self(
            description: $description,
            quantity: $quantity,
            unitPrice: $unitPrice,
            amount: $unitPrice->times($quantity),
        );
    }

    public static function schemaName(): string
    {
        return 'commerce/line-item';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
