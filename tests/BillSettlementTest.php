<?php

use Illuminate\Support\Facades\Event;
use Rushing\Commerce\Data\BillingLineItem;
use Rushing\Commerce\Data\ComposedBill;
use Rushing\Commerce\Data\Customer;
use Rushing\Commerce\Enums\InvoiceStatus;
use Rushing\Commerce\Enums\PaymentStatus;
use Rushing\Commerce\Events\PurchaseCompleted;
use Rushing\Commerce\Settlements;
use Rushing\Commerce\Tests\Support\CannedStripeHttpClient;
use Stripe\ApiRequestor;

/**
 * A Bill whose metered lines carry sub-cent amounts: two $0.004 lines that only
 * survive if the total rounds once (ADR-0002) rather than per line.
 */
function composedBill(): ComposedBill
{
    $lines = [
        new BillingLineItem('subscription', 'Base tier', 29.00),
        new BillingLineItem('per_token', 'Generation usage', 0.004),
        new BillingLineItem('per_token', 'Generation usage', 0.004),
    ];

    return new ComposedBill($lines, array_sum(array_map(fn ($l) => $l->amountUsd, $lines)));
}

it('projects a finalized Bill into an Invoice, preserving lines and rounding the total once', function () {
    $invoice = app(Settlements::class)->invoice(composedBill(), new Customer('cus_1'), billReference: 'bill_42');

    expect($invoice->lineItems)->toHaveCount(3)
        ->and($invoice->status)->toBe(InvoiceStatus::Open)
        ->and($invoice->billReference)->toBe('bill_42')
        // 29.008 USD rounds ONCE to 2901 minor units; per-line rounding would drop the two $0.004 lines to $0.
        ->and($invoice->total->minorUnits)->toBe(2901);
});

it('settles the Invoice into a Payment on the fake driver, referencing the originating Bill', function () {
    Event::fake([PurchaseCompleted::class]);

    $invoice = app(Settlements::class)->invoice(composedBill(), new Customer('cus_1'), billReference: 'bill_42');
    $settlement = app(Settlements::class)->settle($invoice, 'fake');

    expect($settlement->billReference)->toBe('bill_42')
        ->and($settlement->invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($settlement->purchase->payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($settlement->purchase->payment->amount->minorUnits)->toBe(2901);

    Event::assertDispatched(PurchaseCompleted::class);
});

it('settles the Invoice on the stripe driver too — the money-in seam is driver-agnostic', function () {
    config()->set('commerce.stripe.secret', 'sk_test_fake');
    ApiRequestor::setHttpClient(new CannedStripeHttpClient);

    $invoice = app(Settlements::class)->invoice(composedBill(), new Customer('cus_1'), billReference: 'bill_42');
    $settlement = app(Settlements::class)->settle($invoice, 'stripe');

    expect($settlement->invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($settlement->purchase->payment->driver)->toBe('stripe')
        ->and($settlement->purchase->payment->providerRef)->toBe('pi_fake_123')
        ->and($settlement->purchase->payment->amount->minorUnits)->toBe(2901);
});
