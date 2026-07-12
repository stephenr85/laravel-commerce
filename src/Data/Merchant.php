<?php

namespace Rushing\Commerce\Data;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * The party that collects payment and owns the payment-provider account. Fractal:
 * the platform, a satellite, and a broker are each a Merchant with their own
 * account. Credentials are never carried on the DTO — they are resolved out of
 * band by a StripeClientFactory keyed on the Merchant id, so a serialized Merchant
 * never leaks a secret.
 */
class Merchant extends Data implements SchemaIdentity
{
    public function __construct(
        public string $id,
        public ?string $name = null,
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/merchant';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
