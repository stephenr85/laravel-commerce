<?php

use Illuminate\Support\Facades\Event;
use Rushing\Commerce\Contracts\CustomerVault;
use Rushing\Commerce\Contracts\SubscriptionBinder;
use Rushing\Commerce\Data\BillingAddress;
use Rushing\Commerce\Data\Money;
use Rushing\Commerce\Data\Payment;
use Rushing\Commerce\Enums\Cadence;
use Rushing\Commerce\Enums\PaymentStatus;
use Rushing\Commerce\Enums\PurchaseKind;
use Rushing\Commerce\Events\PaymentRefunded;
use Rushing\Commerce\Events\PurchaseCompleted;
use Rushing\Commerce\MoneyIn;
use Rushing\Commerce\Tests\Support\CannedStripeHttpClient;
use Rushing\Commerce\Tests\Support\RecordingCustomerVault;
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

it('charges a saved payment method off-session against the resolved provider customer', function () {
    $vault = new RecordingCustomerVault;
    app()->instance(CustomerVault::class, $vault);

    $order = testOrder(minorUnits: 1900, paymentMethodRef: 'pm_saved_1', offSession: true);
    $purchase = app(MoneyIn::class)->place($order, 'stripe');

    expect($purchase->payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($vault->resolvedCustomer?->id)->toBe($order->customer->id)
        ->and($vault->resolvedMerchant?->id)->toBe('default');

    // Stripe's SDK serializes booleans to 'true'/'false' strings before they reach the transport.
    $params = $this->stripe->requests[0]['params'];
    expect($params['customer'])->toBe('cus_vault_'.$order->customer->id)
        ->and($params['payment_method'])->toBe('pm_saved_1')
        ->and($params['confirm'])->toBe('true')
        ->and($params['off_session'])->toBe('true')
        ->and($params)->not->toHaveKey('setup_future_usage');
});

it('remembers the card presented at checkout and passes a billing address on the fresh path', function () {
    app()->instance(CustomerVault::class, new RecordingCustomerVault);

    $order = testOrder(
        minorUnits: 1500,
        savePaymentMethod: true,
        billingAddress: new BillingAddress(name: 'Ada', line1: '1 Analytical Way', city: 'London', postalCode: 'EC1', country: 'GB'),
    );
    app(MoneyIn::class)->place($order, 'stripe');

    $params = $this->stripe->requests[0]['params'];
    expect($params['customer'])->toBe('cus_vault_'.$order->customer->id)
        ->and($params['setup_future_usage'])->toBe('off_session')
        ->and($params)->not->toHaveKey('payment_method')
        ->and($params['payment_method_data']['billing_details']['name'])->toBe('Ada')
        ->and($params['payment_method_data']['billing_details']['address']['postal_code'])->toBe('EC1')
        ->and($params['payment_method_data']['billing_details']['address']['country'])->toBe('GB');
});

it('normalizes a requires_action intent into a RequiresAction Payment the host can re-prompt', function () {
    app()->instance(CustomerVault::class, new RecordingCustomerVault);
    $this->stripe->paymentIntentStatus = 'requires_action';

    $order = testOrder(minorUnits: 2200, paymentMethodRef: 'pm_saved_1', offSession: true);
    $purchase = app(MoneyIn::class)->place($order, 'stripe');

    expect($purchase->payment->requiresAction())->toBeTrue()
        ->and($purchase->payment->status)->toBe(PaymentStatus::RequiresAction)
        ->and($purchase->payment->providerRef)->toBe('pi_fake_123');
});

it('fails loudly when an Order wants to save a card but no CustomerVault is bound', function () {
    $order = testOrder(savePaymentMethod: true);

    app(MoneyIn::class)->place($order, 'stripe');
})->throws(RuntimeException::class, 'CustomerVault');

it('does not touch the vault for a plain one-off Order', function () {
    $order = testOrder(minorUnits: 1500);
    app(MoneyIn::class)->place($order, 'stripe');

    $params = $this->stripe->requests[0]['params'];
    expect($params)->not->toHaveKey('customer')
        ->and($params)->not->toHaveKey('payment_method')
        ->and($params)->not->toHaveKey('setup_future_usage');
});

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

it('keys an interactive charge on its Order id, so the same Order paid twice sends the same Idempotency-Key', function () {
    $order = testOrder(paymentMethodRef: 'pm_saved_1');
    app()->instance(CustomerVault::class, new RecordingCustomerVault);

    app(MoneyIn::class)->place($order, 'stripe');
    app(MoneyIn::class)->place($order, 'stripe');

    $keys = collect($this->stripe->requests)
        ->filter(fn ($r) => str_contains($r['url'], '/v1/payment_intents'))
        ->map(fn ($r) => collect($r['headers'])->first(fn ($h) => str_starts_with($h, 'Idempotency-Key: ')))
        ->values();

    expect($keys)->toHaveCount(2)
        ->and($keys[0])->toBe("Idempotency-Key: order:{$order->id}")
        ->and($keys[1])->toBe($keys[0]);
});

it('keys two Orders apart, so a fresh purchase is never deduped into an earlier one', function () {
    app(MoneyIn::class)->place($first = testOrder(), 'stripe');
    app(MoneyIn::class)->place($second = testOrder(), 'stripe');

    $headers = collect($this->stripe->requests)->map(fn ($r) => implode("\n", $r['headers']));

    expect($headers[0])->toContain("Idempotency-Key: order:{$first->id}")
        ->and($headers[1])->toContain("Idempotency-Key: order:{$second->id}");
});

it('names the purchase kind and the Order reference on an off-session PaymentIntent, so a webhook can find the charge it settles (owner-review-20261102 STRIPE-BILLING)', function () {
    app()->instance(CustomerVault::class, new RecordingCustomerVault);

    $order = Rushing\Commerce\Data\Order::for(
        customer: new Rushing\Commerce\Data\Customer(id: 'tenant-acme', type: 'tenant'),
        lineItems: [Rushing\Commerce\Data\LineItem::for('Bill 2026-10', Money::of(9900))],
        kind: PurchaseKind::Subscription,
        reference: 'bill-b1-attempt-a1',
        paymentMethodRef: 'pm_mandate_1',
        offSession: true,
    );
    app(MoneyIn::class)->place($order, 'stripe');

    $request = $this->stripe->requests[0];
    expect($request['params']['metadata'])->toMatchArray(['kind' => 'subscription', 'order_reference' => 'bill-b1-attempt-a1', 'customer_id' => 'tenant-acme'])
        ->and($request['params']['off_session'])->toBe('true')
        ->and($request['params']['confirm'])->toBe('true')
        ->and($request['params']['payment_method'])->toBe('pm_mandate_1')
        // Stripe refuses error_on_requires_action on a PaymentIntent whose payment methods the Dashboard manages
        // (measured live in test mode, STRIPE-BILLING): an off-session charge names its type, the saved card.
        ->and($request['params']['error_on_requires_action'])->toBe('true')
        ->and($request['params']['payment_method_types'])->toBe(['card'])
        // One Stripe charge per attempt: the attempt reference is the Idempotency-Key.
        ->and(collect($request['headers'])->first(fn ($h) => str_starts_with($h, 'Idempotency-Key: ')))->toBe('Idempotency-Key: bill-b1-attempt-a1');
});

it('normalizes an off-session card_declined into a Failed Payment and authentication_required into RequiresAction, each with the code and the intent id', function () {
    app()->instance(CustomerVault::class, new RecordingCustomerVault);

    $this->stripe->paymentIntentError = ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'generic_decline', 'message' => 'Your card was declined.', 'payment_intent' => ['id' => 'pi_declined_1', 'object' => 'payment_intent']];
    $declined = app(MoneyIn::class)->place(testOrder(paymentMethodRef: 'pm_1', offSession: true), 'stripe')->payment;

    $this->stripe->paymentIntentError = ['type' => 'card_error', 'code' => 'authentication_required', 'message' => 'Authentication required.', 'payment_intent' => ['id' => 'pi_sca_1', 'object' => 'payment_intent']];
    $stepUp = app(MoneyIn::class)->place(testOrder(paymentMethodRef: 'pm_1', offSession: true), 'stripe')->payment;

    expect($declined->status)->toBe(PaymentStatus::Failed)
        ->and($declined->errorCode)->toBe('generic_decline')
        ->and($declined->providerRef)->toBe('pi_declined_1')
        ->and($stepUp->status)->toBe(PaymentStatus::RequiresAction)
        ->and($stepUp->errorCode)->toBe('authentication_required')
        ->and($stepUp->providerRef)->toBe('pi_sca_1');
});
