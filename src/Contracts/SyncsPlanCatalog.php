<?php

namespace Rushing\Commerce\Contracts;

use Rushing\Commerce\Data\CatalogPlan;
use Rushing\Commerce\Data\Merchant;
use Rushing\Commerce\Data\PlanCatalogRefs;

/**
 * A rail that keeps its own product catalog mirrors a plan's recurring fee into it (owner-review-20261102 O2). The
 * fee stays the price of record: billing and checkout never read the catalog, and a rail without one (fake, none)
 * simply does not implement this. Resolved through the MoneyInManager, so a host names no concrete driver, the same
 * way {@see HoldsPendingPurchases} and {@see ReversesDisputedTransfers} are reached.
 *
 * Both verbs are idempotent and return the refs the host records against the merchant: re-syncing an unchanged plan
 * writes nothing, and a run that lost its write-back recovers the refs from the rail rather than minting twice.
 * Neither verb moves an existing subscriber: a superseded price is archived, never migrated.
 */
interface SyncsPlanCatalog
{
    /** Publish the plan's current fee to the merchant's catalog, reconciling against what the host last recorded. */
    public function syncPlan(CatalogPlan $plan, Merchant $merchant, ?PlanCatalogRefs $recorded = null): PlanCatalogRefs;

    /** Withdraw a plan from the merchant's catalog: its prices and its product stop being offered. */
    public function retirePlan(PlanCatalogRefs $recorded, Merchant $merchant): PlanCatalogRefs;
}
