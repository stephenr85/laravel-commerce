<?php

use Rushing\Commerce\Data\Money;
use Rushing\Commerce\Data\Payment;
use Rushing\Commerce\Data\Purchase;
use Rushing\Commerce\Data\Receipt;
use Rushing\Commerce\Enums\PaymentStatus;
use Rushing\Commerce\Enums\PurchaseKind;
use Rushing\Commerce\Events\PurchaseCompleted;
use Rushing\Commerce\Wallets;

function topupPurchase(string $party, int $minorUnits, string $providerRef, PurchaseKind $kind = PurchaseKind::CreditTopup): Purchase
{
    $amount = Money::of($minorUnits, 'USD');
    $payment = new Payment(id: 'pay-1', orderId: 'ord-1', amount: $amount, status: PaymentStatus::Succeeded, driver: 'stripe', providerRef: $providerRef);

    return new Purchase(
        id: 'pur-1',
        orderId: 'ord-1',
        kind: $kind,
        payment: $payment,
        receipt: new Receipt(id: 'rec-1', paymentId: 'pay-1', orderId: 'ord-1', amount: $amount),
        beneficiaryId: $party,
    );
}

it('funds the beneficiary wallet when a credit-topup purchase completes', function () {
    event(new PurchaseCompleted(topupPurchase('tenant-x', 2500, 'cs_1')));

    expect(app(Wallets::class)->creditedFor('tenant-x', 'usd'))->toBe(25.0);
});

it('funds exactly once on redelivery (idempotent by provider ref)', function () {
    $purchase = topupPurchase('tenant-x', 2500, 'cs_1');

    event(new PurchaseCompleted($purchase));
    event(new PurchaseCompleted($purchase));

    expect(app(Wallets::class)->creditedFor('tenant-x', 'usd'))->toBe(25.0);
});

it('ignores a non-topup purchase', function () {
    event(new PurchaseCompleted(topupPurchase('tenant-x', 2500, 'cs_1', PurchaseKind::Perpetual)));

    expect(app(Wallets::class)->creditedFor('tenant-x', 'usd'))->toBe(0.0);
});
