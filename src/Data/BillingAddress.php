<?php

namespace Rushing\Commerce\Data;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * A postal address for a charge — used for address verification and tax, and
 * carried into the provider's billing-details. Transaction-scoped: it rides an
 * Order, so a single purchase can use a different address than any stored default.
 * The money-in engine models no shipping address; fulfillment is not its concern.
 */
class BillingAddress extends Data implements SchemaIdentity
{
    public function __construct(
        public ?string $name = null,
        public ?string $line1 = null,
        public ?string $line2 = null,
        public ?string $city = null,
        public ?string $state = null,
        public ?string $postalCode = null,
        public ?string $country = null,
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/billing-address';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
