<?php

use Rushing\Commerce\Contracts\CustomerVault;
use Rushing\Commerce\Data\Customer;
use Rushing\Commerce\Data\LineItem;
use Rushing\Commerce\Data\Money;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Drivers\FakeDriver;
use Rushing\Commerce\Enums\PaymentStatus;
use Rushing\Commerce\Enums\PurchaseKind;
use Rushing\Commerce\MoneyIn;
use Rushing\Commerce\Tests\Support\CannedStripeHttpClient;
use Rushing\Commerce\Tests\Support\RecordingCustomerVault;
use Rushing\Commerce\Wallets;
use Stripe\ApiRequestor;

afterEach(fn () => FakeDriver::clearFakes());

/*
|--------------------------------------------------------------------------
| FakeDriver lifecycle simulation — the fake|stripe seam for the reload path
|--------------------------------------------------------------------------
*/

it('succeeds by default with no scripted outcome', function () {
    $payment = (new FakeDriver)->pay(testOrder());

    expect($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->errorCode)->toBeNull();
});

it('simulates a card decline with an error code', function () {
    FakeDriver::fakeDecline('insufficient_funds');

    $payment = (new FakeDriver)->pay(testOrder());

    expect($payment->status)->toBe(PaymentStatus::Failed)
        ->and($payment->errorCode)->toBe('insufficient_funds');
});

it('simulates an off-session step-up (requires_action)', function () {
    FakeDriver::fakeRequiresAction();

    $payment = (new FakeDriver)->pay(testOrder());

    expect($payment->status)->toBe(PaymentStatus::RequiresAction)
        ->and($payment->errorCode)->toBe('authentication_required');
});

it('simulates a transient-infra error by throwing', function () {
    FakeDriver::fakeTransientError();

    (new FakeDriver)->pay(testOrder());
})->throws(RuntimeException::class);

it('consumes scripted outcomes FIFO then falls back to success', function () {
    FakeDriver::fakeDecline('do_not_honor');
    FakeDriver::fakeSuccess();

    $driver = new FakeDriver;

    expect($driver->pay(testOrder())->status)->toBe(PaymentStatus::Failed)   // 1st: scripted decline
        ->and($driver->pay(testOrder())->status)->toBe(PaymentStatus::Succeeded)  // 2nd: scripted success
        ->and($driver->pay(testOrder())->status)->toBe(PaymentStatus::Succeeded); // 3rd: empty -> success
});

it('credits the wallet exactly once for an off-session credit-topup Order via the reused tail', function () {
    FakeDriver::fakeSuccess();

    $order = Order::for(
        customer: new Customer(id: 'tenant-x', name: 'Ada', email: 'ada@example.test'),
        lineItems: [LineItem::for('Auto-reload', Money::of(2500))],
        kind: PurchaseKind::CreditTopup,
        reference: 'autoreload:tenant-x:usd:9000',
        paymentMethodRef: null,
        offSession: true,
    );

    app(MoneyIn::class)->place($order, 'fake', 'tenant-x');

    expect(app(Wallets::class)->creditedFor('tenant-x', 'usd'))->toBe(25.0);
});

/*
|--------------------------------------------------------------------------
| StripeDriver off-session threading — params, idempotency, decline mapping
|--------------------------------------------------------------------------
*/

function stripeTransport(): CannedStripeHttpClient
{
    config()->set('commerce.driver', 'stripe');
    config()->set('commerce.stripe.secret', 'sk_test_fake');
    $transport = new CannedStripeHttpClient;
    ApiRequestor::setHttpClient($transport);

    return $transport;
}

it('threads error_on_requires_action and a reason-seeded idempotency key on the off-session path', function () {
    $transport = stripeTransport();
    app()->instance(CustomerVault::class, new RecordingCustomerVault);

    $order = Order::for(
        customer: new Customer(id: 'tenant-x', name: 'Ada', email: 'ada@example.test'),
        lineItems: [LineItem::for('Auto-reload', Money::of(1900))],
        kind: PurchaseKind::CreditTopup,
        reference: 'autoreload:tenant-x:usd:9000',
        paymentMethodRef: 'pm_saved_1',
        offSession: true,
    );

    app(MoneyIn::class)->place($order, 'stripe');

    $params = $transport->requests[0]['params'];
    expect($params['off_session'])->toBe('true')
        ->and($params['confirm'])->toBe('true')
        ->and($params['error_on_requires_action'])->toBe('true');

    // The idempotency key rides in the request headers, seeded from the Order reference.
    $headers = implode("\n", $transport->requests[0]['headers']);
    expect($headers)->toContain('Idempotency-Key: autoreload:tenant-x:usd:9000');
});

it('normalizes an off-session card decline into a Failed Payment carrying the code', function () {
    $transport = stripeTransport();
    app()->instance(CustomerVault::class, new RecordingCustomerVault);
    $transport->paymentIntentError = [
        'type' => 'card_error',
        'code' => 'card_declined',
        'decline_code' => 'insufficient_funds',
        'message' => 'Your card has insufficient funds.',
        'payment_intent' => ['id' => 'pi_declined_1', 'object' => 'payment_intent'],
    ];

    $order = Order::for(
        customer: new Customer(id: 'tenant-x', name: 'Ada', email: 'ada@example.test'),
        lineItems: [LineItem::for('Auto-reload', Money::of(1900))],
        kind: PurchaseKind::CreditTopup,
        reference: 'autoreload:tenant-x:usd:9000',
        paymentMethodRef: 'pm_saved_1',
        offSession: true,
    );

    $purchase = app(MoneyIn::class)->place($order, 'stripe');

    expect($purchase->payment->status)->toBe(PaymentStatus::Failed)
        ->and($purchase->payment->errorCode)->toBe('insufficient_funds')
        ->and($purchase->payment->providerRef)->toBe('pi_declined_1');
});

it('normalizes an off-session authentication_required into a RequiresAction Payment', function () {
    $transport = stripeTransport();
    app()->instance(CustomerVault::class, new RecordingCustomerVault);
    $transport->paymentIntentError = [
        'type' => 'card_error',
        'code' => 'authentication_required',
        'message' => 'The payment requires authentication.',
        'payment_intent' => ['id' => 'pi_sca_1', 'object' => 'payment_intent'],
    ];

    $order = Order::for(
        customer: new Customer(id: 'tenant-x', name: 'Ada', email: 'ada@example.test'),
        lineItems: [LineItem::for('Auto-reload', Money::of(1900))],
        kind: PurchaseKind::CreditTopup,
        reference: 'autoreload:tenant-x:usd:9000',
        paymentMethodRef: 'pm_saved_1',
        offSession: true,
    );

    $purchase = app(MoneyIn::class)->place($order, 'stripe');

    expect($purchase->payment->status)->toBe(PaymentStatus::RequiresAction)
        ->and($purchase->payment->errorCode)->toBe('authentication_required');
});

it('does not complete a purchase (no wallet credit) when the off-session charge is declined', function () {
    FakeDriver::fakeDecline('insufficient_funds');

    $order = Order::for(
        customer: new Customer(id: 'tenant-x', name: 'Ada', email: 'ada@example.test'),
        lineItems: [LineItem::for('Auto-reload', Money::of(2500))],
        kind: PurchaseKind::CreditTopup,
        reference: 'autoreload:tenant-x:usd:9000',
        offSession: true,
    );

    $purchase = app(MoneyIn::class)->place($order, 'fake', 'tenant-x');

    expect($purchase->payment->succeeded())->toBeFalse()
        ->and(app(Wallets::class)->creditedFor('tenant-x', 'usd'))->toBe(0.0);
});
