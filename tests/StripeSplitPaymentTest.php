<?php

use Illuminate\Support\Facades\Http;
use Rushing\Commerce\Data\Customer;
use Rushing\Commerce\Data\Dispute;
use Rushing\Commerce\Data\LineItem;
use Rushing\Commerce\Data\Money;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Data\Payment;
use Rushing\Commerce\Data\Split;
use Rushing\Commerce\Drivers\StripeDriver;
use Rushing\Commerce\Enums\PaymentStatus;
use Rushing\Commerce\MoneyIn;

/**
 * Ticket 06 (splicewire-marketplace build) — the destination-charge seam on StripeDriver, per
 * docs/adr/0196: `Split`-carrying Orders/Payments go through Http::fake()'d Stripe calls (NOT the
 * SDK's CannedStripeHttpClient), so this suite intentionally does not touch that fixture at all.
 */
beforeEach(function () {
    config()->set('commerce.driver', 'stripe');
    config()->set('commerce.stripe.secret', 'sk_test_fake');
});

function splitOrder(?Split $split, int $minorUnits = 10000): Order
{
    return Order::for(
        customer: new Customer(id: 'cus_1', name: 'Ada', email: 'ada@example.test'),
        lineItems: [LineItem::for('Beam Extension — Pro tier', Money::of($minorUnits))],
        split: $split,
    );
}

it('sends application_fee_amount + transfer_data.destination on a third-party destination charge', function () {
    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_split_123', 'object' => 'payment_intent', 'status' => 'succeeded',
        ], 200),
    ]);

    $order = splitOrder(new Split(platformFee: Money::of(1000), payeeRef: 'acct_seller_1'), minorUnits: 10000);
    $purchase = app(MoneyIn::class)->place($order, 'stripe');

    expect($purchase->payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($purchase->payment->providerRef)->toBe('pi_split_123')
        ->and($purchase->payment->split?->payeeRef)->toBe('acct_seller_1');

    Http::assertSent(function ($request) use ($order) {
        return $request->url() === 'https://api.stripe.com/v1/payment_intents'
            && $request->method() === 'POST'
            && (int) $request['amount'] === 10000
            && (int) $request['application_fee_amount'] === 1000
            && $request['transfer_data']['destination'] === 'acct_seller_1'
            // The split path posts over Http, so its Idempotency-Key is a header it sets itself.
            && $request->header('Idempotency-Key') === ["order:{$order->id}"];
    });
});

it('forces the fee to zero on a first-party Split with no payee — same payWithSplit() entry point, no Connect params sent', function () {
    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_split_first_party', 'object' => 'payment_intent', 'status' => 'succeeded',
        ], 200),
    ]);

    $order = splitOrder(new Split(platformFee: Money::zero(), payeeRef: null), minorUnits: 2000);
    $purchase = app(MoneyIn::class)->place($order, 'stripe');

    expect($purchase->payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($purchase->payment->split?->platformFee->isZero())->toBeTrue()
        ->and($purchase->payment->split?->payeeRef)->toBeNull();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.stripe.com/v1/payment_intents'
            && ! array_key_exists('application_fee_amount', $request->data())
            && ! array_key_exists('transfer_data', $request->data());
    });
});

it('exercises a third-party and a first-party charge through the identical StripeDriver::pay() dispatch', function () {
    Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_shared', 'status' => 'succeeded'], 200)]);

    $paid = splitOrder(new Split(platformFee: Money::of(500), payeeRef: 'acct_seller_2'), minorUnits: 5000);
    $firstParty = splitOrder(new Split(platformFee: Money::zero(), payeeRef: null), minorUnits: 5000);

    $driver = app(StripeDriver::class);

    // Both dispatch through the exact same pay() method — no isFirstParty()-style branch exists
    // in StripeDriver at all (grep-confirmed by this ticket's own ADR); the only difference is
    // the DATA each Order carries.
    $paidPayment = $driver->pay($paid);
    $firstPartyPayment = $driver->pay($firstParty);

    expect($paidPayment->split?->payeeRef)->toBe('acct_seller_2')
        ->and($firstPartyPayment->split?->payeeRef)->toBeNull();
});

it('prorates both the platform fee and the seller transfer on a partial refund, computed explicitly', function () {
    Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_split_1'], 200)]);

    // A $100 charge with a $10 platform fee ($90 transferred). A $40 partial refund should
    // reverse 40% of the $90 transfer = $36 (4000 cents), floor-rounded.
    $payment = new Payment(
        id: 'pay_1',
        orderId: 'ord_1',
        amount: Money::of(10000),
        status: PaymentStatus::Succeeded,
        driver: 'stripe',
        providerRef: 'pi_split_1',
        split: new Split(platformFee: Money::of(1000), payeeRef: 'acct_seller_3'),
    );

    $refund = app(StripeDriver::class)->refundWithSplit($payment, Money::of(4000));

    expect($refund->amount->minorUnits)->toBe(4000)
        ->and($refund->providerRef)->toBe('re_split_1');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.stripe.com/v1/refunds'
            && (int) $request['amount'] === 4000
            && $request['refund_application_fee'] === 'true'
            && $request['reverse_transfer'] === 'true'
            && (int) $request['transfer_reversal_amount'] === 3600;
    });
});

it('does not send split-refund params for a Payment with no payee', function () {
    Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_split_2'], 200)]);

    $payment = new Payment(
        id: 'pay_2', orderId: 'ord_2', amount: Money::of(2000), status: PaymentStatus::Succeeded,
        driver: 'stripe', providerRef: 'pi_split_2',
        split: new Split(platformFee: Money::zero(), payeeRef: null),
    );

    app(StripeDriver::class)->refundWithSplit($payment);

    Http::assertSent(fn ($request) => ! array_key_exists('reverse_transfer', $request->data())
        && ! array_key_exists('refund_application_fee', $request->data()));
});

it('reverses the Stripe transfer proportionally to the disputed amount on charge.dispute.created', function () {
    Http::fake([
        'api.stripe.com/v1/charges/ch_disputed_1' => Http::response([
            'id' => 'ch_disputed_1', 'amount' => 10000, 'application_fee_amount' => 1000, 'transfer' => 'tr_seller_1',
        ], 200),
        'api.stripe.com/v1/transfers/tr_seller_1/reversals' => Http::response(['id' => 'trr_1'], 200),
    ]);

    // A $50 (5000 cent) partial dispute against a $100 charge with a $90 transfer: reverse 50%
    // of $90 = $45 (4500 cents).
    $dispute = new Dispute(chargeRef: 'ch_disputed_1', amount: Money::of(5000));

    app(StripeDriver::class)->reverseTransferForDispute($dispute);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/charges/ch_disputed_1'
        && $request->method() === 'GET');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.stripe.com/v1/transfers/tr_seller_1/reversals'
            && $request->method() === 'POST'
            && (int) $request['amount'] === 4500;
    });
});

it('is a no-op when the disputed charge was never a destination charge (no transfer)', function () {
    Http::fake([
        'api.stripe.com/v1/charges/ch_plain_1' => Http::response(['id' => 'ch_plain_1', 'amount' => 5000, 'transfer' => null], 200),
    ]);

    app(StripeDriver::class)->reverseTransferForDispute(new Dispute(chargeRef: 'ch_plain_1', amount: Money::of(5000)));

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/reversals'));
});
