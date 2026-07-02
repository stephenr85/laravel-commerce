<?php

declare(strict_types=1);

use Rushing\Commerce\Data\Discount;
use Rushing\Commerce\Data\Money;
use Rushing\Commerce\Data\Offer;
use Rushing\Commerce\Data\Plan;
use Rushing\Commerce\Data\Price;
use Rushing\Commerce\Data\TaxLine;
use Rushing\Commerce\Enums\Cadence;
use Rushing\Commerce\Enums\DiscountKind;

it('defines an Offer as something purchasable at a Price with a Cadence', function () {
    $offer = new Offer(
        slug: 'own-a-song',
        name: 'Own a song',
        price: new Price(Money::of(900), Cadence::OneTime),
    );

    expect($offer->price->amount->minorUnits)->toBe(900)
        ->and($offer->price->cadence)->toBe(Cadence::OneTime);
});

it('reduces a subtotal with a percent code and an amount code, never below zero', function () {
    $subtotal = Money::of(1000);

    expect((new Discount(DiscountKind::Percent, 20, 'SAVE20'))->applyTo($subtotal)->minorUnits)->toBe(800)
        ->and((new Discount(DiscountKind::Amount, 300))->applyTo($subtotal)->minorUnits)->toBe(700)
        ->and((new Discount(DiscountKind::Amount, 5000))->applyTo($subtotal)->minorUnits)->toBe(0);
});

it('adds a jurisdiction tax as its own line computed from the subtotal', function () {
    $tax = TaxLine::forSubtotal(Money::of(1000), 'US-CA', 0.0825);

    expect($tax->jurisdiction)->toBe('US-CA')
        ->and($tax->amount->minorUnits)->toBe(83); // round(1000 * 0.0825)
});

it('carries feature-gate flags and a configurable unit-of-sale key, host-mapped', function () {
    $plan = new Plan(
        slug: 'compliance-pro',
        name: 'Compliance Pro',
        components: [],
        features: ['state_overlays', 'localization'],
        unitOfSale: 'location',
    );

    expect($plan->hasFeature('state_overlays'))->toBeTrue()
        ->and($plan->hasFeature('data_comps'))->toBeFalse()
        ->and($plan->unitOfSale)->toBe('location');
});

it('projects catalog DTOs through the data array representation', function () {
    $price = new Price(Money::of(1900, 'USD'), Cadence::Recurring);

    expect($price->toArray())->toBe([
        'amount' => ['minorUnits' => 1900, 'currency' => 'USD'],
        'cadence' => 'recurring',
    ]);
});
