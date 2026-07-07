<?php

namespace Rushing\Commerce\Data;

use Rushing\LaravelDataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * A Customer as the provider knows them on one Merchant's account — the durable
 * link between our host-owned Customer id and the provider's opaque customer
 * reference. Scoped to a single Merchant account: the same Customer is a different
 * VaultedCustomer on a different account, because saved cards never cross accounts.
 */
class VaultedCustomer extends Data implements SchemaIdentity
{
    public function __construct(
        public string $customerId,
        public string $providerRef,
        public string $merchantId,
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/vaulted-customer';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
