<?php

declare(strict_types=1);

namespace Rushing\Commerce\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Rushing\Commerce\Models\Redemption;

/**
 * Fires when a bearer redeems a Gift's code and it binds to them. The host reacts
 * to grant access, deliver the artifact, and seed whatever loop follows — what
 * redemption *grants* is host-owned and never resolved by the engine.
 */
final class GiftRedeemed
{
    use Dispatchable;

    public function __construct(public Redemption $redemption) {}
}
