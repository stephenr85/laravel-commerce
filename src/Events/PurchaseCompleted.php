<?php

declare(strict_types=1);

namespace Rushing\Commerce\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Rushing\Commerce\Data\Purchase;

/**
 * Fires when a paid Order completes into a Purchase. The host reacts here to map
 * the Purchase onto its own access model (Entitlement/Grant) — the engine never
 * knows what the Purchase grants.
 */
final class PurchaseCompleted
{
    use Dispatchable;

    public function __construct(public Purchase $purchase) {}
}
