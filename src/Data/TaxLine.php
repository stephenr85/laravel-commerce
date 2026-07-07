<?php

namespace Rushing\Commerce\Data;

use Rushing\LaravelDataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * A jurisdiction's tax applied to an Order as its own line. `rate` is a fraction
 * (0.08 = 8%); `amount` is the computed Money for the given subtotal.
 */
class TaxLine extends Data implements SchemaIdentity
{
    public function __construct(
        public string $jurisdiction,
        public float $rate,
        public Money $amount,
    ) {}

    public static function forSubtotal(Money $subtotal, string $jurisdiction, float $rate): self
    {
        return new self(
            jurisdiction: $jurisdiction,
            rate: $rate,
            amount: Money::of((int) round($subtotal->minorUnits * $rate), $subtotal->currency),
        );
    }

    public static function schemaName(): string
    {
        return 'commerce/tax-line';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
