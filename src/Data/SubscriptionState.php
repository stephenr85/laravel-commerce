<?php

namespace Rushing\Commerce\Data;

use Rushing\Commerce\Contracts\SubscriptionBinder;
use Rushing\Commerce\Contracts\SubscriptionProjector;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * The neutral inbound state of a provider subscription — the read complement to the
 * outbound {@see SubscriptionBinder}. A driver translates a
 * provider webhook into this; a host {@see SubscriptionProjector}
 * maps `priceRef`→its plan and `customerRef`→its billing party, and uses `active` to decide
 * whether access is granted. The engine never learns the host's plan/entitlement vocabulary.
 */
class SubscriptionState extends Data implements SchemaIdentity
{
    public function __construct(
        public string $customerRef,
        public string $priceRef,
        public string $status,
        public bool $active,
        public ?int $currentPeriodEnd = null,
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/subscription-state';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
