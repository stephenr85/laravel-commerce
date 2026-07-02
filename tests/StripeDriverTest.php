<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Rushing\Commerce\Contracts\SubscriptionBinder;
use Rushing\Commerce\Data\Money;
use Rushing\Commerce\Data\Payment;
use Rushing\Commerce\Enums\Cadence;
use Rushing\Commerce\Enums\PaymentStatus;
use Rushing\Commerce\Enums\PurchaseKind;
use Rushing\Commerce\Events\PaymentRefunded;
use Rushing\Commerce\Events\PurchaseCompleted;
use Rushing\Commerce\MoneyIn;
use Rushing\Commerce\Tests\Support\CannedStripeHttpClient;
use Rushing\Commerce\Tests\Support\RecordingSubscriptionBinder;
use Stripe\ApiRequestor;

beforeEach(function () {
    config()->set('commerce.driver', 'stripe');
    config()->set('commerce.stripe.secret', 'sk_test_fake');
    $this->stripe = new CannedStripeHttpClient;
    ApiRequestor::setHttpClient($this->stripe);
});

it('captures a one-off Payment/Receipt/Purchase through the stripe driver', function () {
    $order = testOrder(minorUnits: 1500);

    $purchase = app(MoneyIn::class)->place($order, 'stripe');

    expect($purchase->payment->driver)->toBe('stripe')
        ->and($purchase->payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($purchase->payment->providerRef)->toBe('pi_fake_123')
        ->and($purchase->payment->merchantId)->toBe('default')
        ->and($purchase->payment->amount->minorUnits)->toBe(1500)
        ->and($purchase->receipt->amount->minorUnits)->toBe(1500);

    // The driver spoke to Stripe's PaymentIntents endpoint with the Order's minor units.
    expect($this->stripe->requests)->toHaveCount(1)
        ->and($this->stripe->requests[0]['url'])->toContain('/v1/payment_intents')
        ->and($this->stripe->requests[0]['params']['amount'])->toBe(1500)
        ->and($this->stripe->requests[0]['params']['currency'])->toBe('usd');
});

it('reverses a stripe Payment into a Refund', function () {
    $payment = new Payment(
        id: 'pay_1',
        orderId: 'ord_1',
        amount: Money::of(1500),
        status: PaymentStatus::Succeeded,
        driver: 'stripe',
        providerRef: 'pi_fake_123',
        merchantId: 'default',
    );

    Event::fake([PaymentRefunded::class]);

    $refund = app(MoneyIn::class)->refund($payment);

    expect($refund->driver)->toBe('stripe')
        ->and($refund->providerRef)->toBe('re_fake_123')
        ->and($refund->amount->minorUnits)->toBe(1500);

    expect(end($this->stripe->requests)['url'])->toContain('/v1/refunds');
    Event::assertDispatched(PaymentRefunded::class);
});

it('binds a recurring Order to a subscription through the host SubscriptionBinder', function () {
    $binder = new RecordingSubscriptionBinder;
    app()->instance(SubscriptionBinder::class, $binder);

    $order = testOrder(minorUnits: 2900, cadence: Cadence::Recurring);
    $purchase = app(MoneyIn::class)->place($order, 'stripe');

    expect($binder->boundOrder?->id)->toBe($order->id)
        ->and($binder->boundMerchant?->id)->toBe('default')
        ->and($purchase->payment->providerRef)->toBe('sub_fake_123')
        ->and($purchase->kind)->toBe(PurchaseKind::Subscription);

    // Recurring never touches the one-off PaymentIntents endpoint.
    expect($this->stripe->requests)->toBeEmpty();
});

it('fails loudly on a recurring Order with no SubscriptionBinder bound', function () {
    $order = testOrder(cadence: Cadence::Recurring);

    app(MoneyIn::class)->place($order, 'stripe');
})->throws(RuntimeException::class, 'SubscriptionBinder');

it('drives the same feature path through both fake and stripe drivers to equivalent records', function (string $driver) {
    Event::fake([PurchaseCompleted::class]);

    $order = testOrder(minorUnits: 4200);
    $purchase = app(MoneyIn::class)->place($order, $driver);

    expect($purchase->payment->driver)->toBe($driver)
        ->and($purchase->payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($purchase->payment->amount->minorUnits)->toBe(4200)
        ->and($purchase->receipt->paymentId)->toBe($purchase->payment->id)
        ->and($purchase->orderId)->toBe($order->id);

    Event::assertDispatched(PurchaseCompleted::class);
})->with(['fake', 'stripe']);
