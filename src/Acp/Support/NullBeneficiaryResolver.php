<?php

namespace Rushing\Commerce\Acp\Support;

use Rushing\Commerce\Acp\Contracts\BeneficiaryResolver;
use Rushing\Commerce\Acp\Data\AgentProvenance;

/**
 * The default {@see BeneficiaryResolver}: resolves no beneficiary. A host that wants a completed ACP
 * checkout to grant value (fund a wallet, provision an entitlement) binds its own resolver; until then
 * the checkout records the sale but grants nothing (money-in without value-out) — the safe default.
 */
class NullBeneficiaryResolver implements BeneficiaryResolver
{
    public function resolve(?AgentProvenance $provenance): ?string
    {
        return null;
    }
}
