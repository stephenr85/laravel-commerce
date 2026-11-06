<?php

namespace Rushing\Commerce\Contracts;

use Rushing\Commerce\Data\Purchase;
use Rushing\Commerce\FundingPolicy;

/**
 * A rail whose confirmed plan card setup FUNDS the plan by itself (owner-review-20261102 FUNDING; owner, 2026-11-06:
 * "why isn't it just based on the driver?"). Funding is the rail's policy, not a global rule: each rail decides whether
 * the card a setup saved is enough to count the plan as paid for, the same optional-capability way
 * {@see HoldsPendingPurchases} and {@see SyncsPlanCatalog} are declared.
 *
 * A rail that does not implement this keeps BUY-04's rule: a saved card is not a payment, and funding lands with the
 * first charged Bill. Read it through {@see FundingPolicy}, which consults the ACTIVE rail and checks what every rail
 * shares (a succeeded Subscription purchase that charged nothing, made on that rail) before asking the rail.
 */
interface SettlesFundingOnSetup
{
    /** Whether this confirmed card setup (a Subscription purchase of 0 made on this rail) funds the plan it was opened for. */
    public function fundsOnSetup(Purchase $setup): bool;
}
