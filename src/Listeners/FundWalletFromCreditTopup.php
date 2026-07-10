<?php

namespace Rushing\Commerce\Listeners;

use Rushing\Commerce\Enums\PurchaseKind;
use Rushing\Commerce\Events\PurchaseCompleted;
use Rushing\Commerce\Wallets;

/**
 * Engine-owned wallet funding: when a paid Order (or a driver-translated provider event)
 * completes into a Credit-topup {@see PurchaseCompleted}, add the value to the beneficiary's
 * Wallet. Idempotent on the payment's provider reference, so a redelivered webhook credits
 * once. Provider-agnostic — the Stripe driver only translates its event into the Purchase;
 * this is where every host gets top-up funding without wiring its own listener.
 */
class FundWalletFromCreditTopup
{
    public function __construct(private Wallets $wallets) {}

    public function handle(PurchaseCompleted $event): void
    {
        $purchase = $event->purchase;

        if ($purchase->kind !== PurchaseKind::CreditTopup || $purchase->beneficiaryId === null) {
            return;
        }

        $unit = (string) config('commerce.credit.unit', 'usd');

        // A currency-denominated wallet funds in major units (minor/100). A non-currency
        // unit (tokens/generations) would apply a host rate — a later seam, usd is 1:1.
        $amount = $purchase->payment->amount->minorUnits / 100;

        $ref = $purchase->payment->providerRef ?? $purchase->orderId;

        $this->wallets->topUpOnce($purchase->beneficiaryId, $unit, $amount, 'topup:'.$ref);
    }
}
