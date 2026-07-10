<?php

namespace Rushing\Commerce;

use Rushing\Commerce\Contracts\UsageMeter;
use Rushing\Commerce\Models\CreditEntry;

/**
 * The Wallet read/write surface: top up a party's balance (a completed Purchase
 * converts Money → Credit at a host-configured rate, then lands here) and read the
 * credited total. `budgetSource()` presents the balance to the spend gate as a
 * {@see Credit}. The unit is host-defined (tokens / generations / USD) and never
 * forced to a currency.
 */
class Wallets
{
    public function topUp(string $partyId, string $unit, float $amount, ?string $purchaseId = null, ?string $reason = null): CreditEntry
    {
        return CreditEntry::create([
            'party_id' => $partyId,
            'unit' => $unit,
            'amount' => $amount,
            'purchase_id' => $purchaseId,
            'reason' => $reason,
        ]);
    }

    /**
     * Top up once for a given idempotency reason: a no-op returning null when a CreditEntry
     * with that reason already exists for the party. The reason (not purchase_id, a UUID FK)
     * carries the provider ref so redelivered provider events credit exactly once.
     */
    public function topUpOnce(string $partyId, string $unit, float $amount, string $reason): ?CreditEntry
    {
        $exists = CreditEntry::query()
            ->where('party_id', $partyId)
            ->where('reason', $reason)
            ->exists();

        return $exists ? null : $this->topUp($partyId, $unit, $amount, null, $reason);
    }

    public function creditedFor(string $partyId, string $unit): float
    {
        return (float) CreditEntry::query()
            ->where('party_id', $partyId)
            ->where('unit', $unit)
            ->sum('amount');
    }

    public function budgetSource(string $partyId, string $unit, UsageMeter $meter, ?float $warnFraction = null): Credit
    {
        return new Credit(
            partyId: $partyId,
            unit: $unit,
            credited: $this->creditedFor($partyId, $unit),
            meter: $meter,
            warnFraction: $warnFraction ?? (float) config('commerce.credit.warn_fraction', 0.8),
        );
    }
}
