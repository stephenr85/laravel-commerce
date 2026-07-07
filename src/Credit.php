<?php

namespace Rushing\Commerce;

use Rushing\Commerce\Budget\BudgetAssessment;
use Rushing\Commerce\Budget\BudgetGate;
use Rushing\Commerce\Budget\BudgetSource;
use Rushing\Commerce\Contracts\UsageMeter;

/**
 * A party's prepaid balance presented to the spend gate: `cap = credited`,
 * `spend = debited`, so the existing {@see BudgetGate}
 * gates prepaid usage with no gate rewrite. Credited is owned by the package
 * (the Wallet ledger); debited is read from the host's usage ledger through a
 * {@see UsageMeter}. Balance = credited − debited.
 */
class Credit implements BudgetSource
{
    public function __construct(
        private string $partyId,
        private string $unit,
        private float $credited,
        private UsageMeter $meter,
        private float $warnFraction,
    ) {}

    public function assess(): BudgetAssessment
    {
        return new BudgetAssessment(
            cap: $this->credited,
            spend: $this->meter->debitedFor($this->partyId, $this->unit),
            warnFraction: $this->warnFraction,
        );
    }

    public function balance(): float
    {
        return $this->credited - $this->meter->debitedFor($this->partyId, $this->unit);
    }
}
