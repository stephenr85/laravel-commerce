<?php

declare(strict_types=1);

use Rushing\Commerce\Data\BillingAddress;
use Rushing\Commerce\Data\Invoice;
use Rushing\Commerce\Data\Merchant;
use Rushing\Commerce\Data\Money;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Data\Payment;
use Rushing\Commerce\Data\Purchase;
use Rushing\Commerce\Data\Settlement;
use Rushing\Commerce\Data\SetupTicket;
use Rushing\Commerce\Data\VaultedCustomer;
use Rushing\Commerce\Data\VaultedPaymentMethod;
use Rushing\Commerce\Enums\PaymentStatus;
use Rushing\LaravelDataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

it('exposes the neutral records as laravel-data with a schema identity', function (string $class) {
    expect(is_subclass_of($class, Data::class))->toBeTrue()
        ->and(is_subclass_of($class, SchemaIdentity::class))->toBeTrue()
        ->and($class::schemaName())->toStartWith('commerce/')
        ->and($class::schemaVersion())->toBeInt();
})->with([
    Money::class,
    Order::class,
    Payment::class,
    Purchase::class,
    Merchant::class,
    Invoice::class,
    Settlement::class,
    BillingAddress::class,
    VaultedCustomer::class,
    SetupTicket::class,
    VaultedPaymentMethod::class,
]);

it('round-trips an Order through the data array representation', function () {
    $order = testOrder(minorUnits: 1234);

    $array = $order->toArray();

    expect($array['total']['minorUnits'])->toBe(1234)
        ->and($array['lineItems'])->toHaveCount(1)
        ->and($array['cadence'])->toBe('one_time');
});

it('defaults the vault fields to a card-less, on-session, unsaved Order', function () {
    $order = testOrder();

    expect($order->paymentMethodRef)->toBeNull()
        ->and($order->savePaymentMethod)->toBeFalse()
        ->and($order->offSession)->toBeFalse()
        ->and($order->billingAddress)->toBeNull();
});

it('carries a saved payment method, save/off-session flags, and a billing address', function () {
    $order = testOrder(
        paymentMethodRef: 'pm_saved_123',
        savePaymentMethod: true,
        offSession: true,
        billingAddress: new BillingAddress(name: 'Ada', postalCode: '90210', country: 'US'),
    );

    $array = $order->toArray();

    expect($array['paymentMethodRef'])->toBe('pm_saved_123')
        ->and($array['savePaymentMethod'])->toBeTrue()
        ->and($array['offSession'])->toBeTrue()
        ->and($array['billingAddress']['postalCode'])->toBe('90210')
        ->and($array['billingAddress']['country'])->toBe('US');
});

it('exposes a neutral requires-action payment status distinct from succeeded', function () {
    expect(PaymentStatus::RequiresAction->value)->toBe('requires_action');

    $payment = new Payment(
        id: 'p_1',
        orderId: 'o_1',
        amount: Money::of(1000),
        status: PaymentStatus::RequiresAction,
        driver: 'stripe',
        providerRef: 'pi_needs_auth',
    );

    expect($payment->requiresAction())->toBeTrue()
        ->and($payment->succeeded())->toBeFalse();
});
