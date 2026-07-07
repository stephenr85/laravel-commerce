<?php

namespace Rushing\Commerce\Contracts;

/**
 * The debit side of a Wallet, read through the host's existing usage ledger — the
 * package owns the *credit* (money-in) side but never a usage ledger of its own
 * (fable's `TokenTransaction`, the platform's `CostEvent` stay authoritative). A
 * host implements this to report how much of a party's balance has been consumed,
 * in the same host-defined unit the credits were bought in.
 */
interface UsageMeter
{
    public function debitedFor(string $partyId, string $unit): float;
}
