<?php

declare(strict_types=1);

use Laravel\Cashier\Cashier;
use Rushing\Commerce\Drivers\StripeDriver;
use Rushing\Commerce\MoneyIn;
use Rushing\Commerce\MoneyInManager;

it('boots and binds the money-in service without a payment rail', function () {
    expect(app(MoneyIn::class))->toBeInstanceOf(MoneyIn::class)
        ->and(app(MoneyInManager::class))->toBeInstanceOf(MoneyInManager::class);
});

it('does not require laravel/cashier to boot', function () {
    // The metering plane must be usable with Cashier absent (thingson.tv).
    expect(class_exists(Cashier::class))->toBeFalse();
});

it('resolves the fake driver as the configured default', function () {
    expect(app(MoneyIn::class)->driver()->name())->toBe('fake');
});

it('resolves the stripe driver from the container when configured', function () {
    config()->set('commerce.driver', 'stripe');

    $driver = app(MoneyIn::class)->driver();

    expect($driver)->toBeInstanceOf(StripeDriver::class)
        ->and($driver->name())->toBe('stripe');
});
