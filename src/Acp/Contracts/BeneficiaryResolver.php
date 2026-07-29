<?php

namespace Rushing\Commerce\Acp\Contracts;

use Rushing\Commerce\Acp\Data\AgentProvenance;

/**
 * Host seam: map an ACP checkout's agent provenance to the opaque id of the account/wallet the
 * fulfillment should grant to. ACP checkout is agent-driven and off-session — there is no logged-in
 * buyer — so the beneficiary can't be inferred from an auth session; the host resolves it (e.g. from
 * the tenant that owns the store, or a customer-key lookup).
 *
 * The engine stays account-blind: it hands `complete()` the resolved id and threads it to
 * `MoneyIn::place(beneficiaryId:)` → `PurchaseCompleted`, never learning what the id refers to. Returns
 * null when no beneficiary resolves (the default), in which case grant listeners early-return and only
 * the sale is recorded — money-in without value-out.
 */
interface BeneficiaryResolver
{
    public function resolve(?AgentProvenance $provenance): ?string;
}
