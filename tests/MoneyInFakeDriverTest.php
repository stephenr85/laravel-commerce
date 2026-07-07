<?php

use Illuminate\Support\Facades\Event;
use Rushing\Commerce\Data\Payment;
use Rushing\Commerce\Data\Purchase;
use Rushing\Commerce\Data\Receipt;
use Rushing\Commerce\Enums\Cadence;
use Rushing\Commerce\Enums\PaymentStatus;
use Rushing\Commerce\Enums\PurchaseKind;
use Rushing\Commerce\Events\PurchaseCompleted;
use Rushing\Commerce\MoneyIn;

it('drives an Order through the fake driver into Payment, Receipt, and a completed Purchase', function () {
    Event::fake([PurchaseCompleted::class]);

    $order = testOrder(minorUnits: 900);

    $purchase = app(MoneyIn::class)->place($order);

    expect($purchase)->toBeInstanceOf(Purchase::class)
        ->and($purchase->orderId)->toBe($order->id)
        ->and($purchase->payment)->toBeInstanceOf(Payment::class)
        ->and($purchase->receipt)->toBeInstanceOf(Receipt::class);

    Event::assertDispatched(
        PurchaseCompleted::class,
        fn (PurchaseCompleted $e) => $e->purchase->id === $purchase->id,
    );
});

it('produces a succeeded Payment and Receipt that reflect the originating Order', function () {
    $order = testOrder(minorUnits: 1900);

    $purchase = app(MoneyIn::class)->place($order);

    expect($purchase->payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($purchase->payment->driver)->toBe('fake')
        ->and($purchase->payment->amount->minorUnits)->toBe(1900)
        ->and($purchase->payment->amount->currency)->toBe('USD')
        ->and($purchase->receipt->amount->minorUnits)->toBe(1900)
        ->and($purchase->receipt->paymentId)->toBe($purchase->payment->id)
        ->and($purchase->receipt->orderId)->toBe($order->id);
});

it('carries the Order cadence through to the Purchase kind', function () {
    $oneOff = app(MoneyIn::class)->place(testOrder(cadence: Cadence::OneTime));
    $sub = app(MoneyIn::class)->place(testOrder(cadence: Cadence::Recurring));

    expect($oneOff->kind)->toBe(PurchaseKind::Perpetual)
        ->and($sub->kind)->toBe(PurchaseKind::Subscription);
});

it('sums line items into the Order total', function () {
    $order = testOrder(minorUnits: 500);

    expect($order->total->minorUnits)->toBe(500)
        ->and($order->lineItems)->toHaveCount(1);
});

it('reverses a Payment through the same driver', function () {
    $purchase = app(MoneyIn::class)->place(testOrder(minorUnits: 900));

    $refund = app(MoneyIn::class)->refund($purchase->payment);

    expect($refund->paymentId)->toBe($purchase->payment->id)
        ->and($refund->amount->minorUnits)->toBe(900)
        ->and($refund->driver)->toBe('fake');
});
