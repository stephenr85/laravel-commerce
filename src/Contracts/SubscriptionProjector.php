<?php

namespace Rushing\Commerce\Contracts;

use Rushing\Commerce\Data\SubscriptionState;

/**
 * The inbound complement to {@see SubscriptionBinder}: a host projects a provider
 * subscription's neutral {@see SubscriptionState} onto its own access model (plan,
 * entitlements, spend cap). The host owns the mapping and any skip rules (e.g. a
 * brokered tenant that carries no subscription of its own); the engine only delivers
 * the neutral state, translated from the provider payload by the driver.
 */
interface SubscriptionProjector
{
    public function apply(SubscriptionState $state): void;
}
