<?php

namespace Rushing\Commerce\Data;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * A card on file, as neutral display data plus its opaque provider reference. The
 * `ref` is what a later Order carries to charge this card; `brand`/`last4`/expiry
 * are for showing the Customer which card they saved. No PAN ever lives here — the
 * number was tokenized by the provider and never touched our servers.
 */
class VaultedPaymentMethod extends Data implements SchemaIdentity
{
    public function __construct(
        public string $ref,
        public string $brand,
        public string $last4,
        public int $expMonth,
        public int $expYear,
        public bool $isDefault = false,
        public ?BillingAddress $billingAddress = null,
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/payment-method';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
