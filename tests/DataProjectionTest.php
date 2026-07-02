<?php

declare(strict_types=1);

use Rushing\Commerce\Data\Invoice;
use Rushing\Commerce\Data\Merchant;
use Rushing\Commerce\Data\Money;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Data\Payment;
use Rushing\Commerce\Data\Purchase;
use Rushing\Commerce\Data\Settlement;
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
]);

it('round-trips an Order through the data array representation', function () {
    $order = testOrder(minorUnits: 1234);

    $array = $order->toArray();

    expect($array['total']['minorUnits'])->toBe(1234)
        ->and($array['lineItems'])->toHaveCount(1)
        ->and($array['cadence'])->toBe('one_time');
});
