<?php

namespace Rushing\Commerce\Tests\Support;

use Rushing\Commerce\Contracts\UsageMeter;

/**
 * A stand-in for a host's usage ledger — the debit side the package does not own.
 * Tests set how much a party has consumed; the Wallet reads it through this seam.
 */
class ArrayUsageMeter implements UsageMeter
{
    /** @var array<string, float> */
    private array $debits = [];

    public function set(string $partyId, string $unit, float $debited): self
    {
        $this->debits[$partyId.'|'.$unit] = $debited;

        return $this;
    }

    public function debitedFor(string $partyId, string $unit): float
    {
        return $this->debits[$partyId.'|'.$unit] ?? 0.0;
    }
}
