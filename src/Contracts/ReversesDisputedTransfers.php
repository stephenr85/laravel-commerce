<?php

namespace Rushing\Commerce\Contracts;

use Rushing\Commerce\Data\Dispute;
use Rushing\Commerce\Data\Merchant;

/**
 * A rail that moves money to a payee through destination transfers can claw the payee's share back when the buyer
 * disputes the charge. Resolved through the MoneyInManager, so a dispute listener names no concrete driver
 * (purchase-walkthrough BUY-02, rule BUY-1). A rail without transfers (fake, none) simply does not implement it.
 */
interface ReversesDisputedTransfers
{
    public function reverseTransferForDispute(Dispute $dispute, ?Merchant $merchant = null): void;
}
