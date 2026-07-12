<?php

namespace Rushing\Commerce\Data;

use Rushing\Commerce\Enums\DiscountKind;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * A reduction applied to an Order or line — including a promo/gift/referral code
 * (the `code`). `Percent` value is whole percent (20 = 20% off); `Amount` value is
 * minor units off. The reduction never drives the amount below zero.
 */
class Discount extends Data implements SchemaIdentity
{
    public function __construct(
        public DiscountKind $kind,
        public int $value,
        public ?string $code = null,
    ) {}

    public function applyTo(Money $subtotal): Money
    {
        $reduction = $this->kind === DiscountKind::Percent
            ? (int) round($subtotal->minorUnits * $this->value / 100)
            : $this->value;

        return Money::of(max(0, $subtotal->minorUnits - $reduction), $subtotal->currency);
    }

    public static function schemaName(): string
    {
        return 'commerce/discount';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
