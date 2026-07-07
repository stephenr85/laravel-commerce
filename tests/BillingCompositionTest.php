<?php

use Rushing\Commerce\Billing\BillComposer;
use Rushing\Commerce\Billing\BillingPeriod;
use Rushing\Commerce\Billing\ComponentRegistry;
use Rushing\Commerce\Billing\Pricing\PricingStrategyRegistry;
use Rushing\Commerce\Data\Plan;
use Rushing\Commerce\Tests\Support\FlatFeeComponent;
use Rushing\Commerce\Tests\Support\PerUnitComponent;

beforeEach(function () {
    $registry = app(ComponentRegistry::class);
    $registry->register('FlatFee', fn () => new FlatFeeComponent);
    $registry->register('PerUnit', fn () => new PerUnitComponent(app(PricingStrategyRegistry::class)));
});

it('composes a Plan of ordered components into the expected line items and total', function () {
    $plan = new Plan(
        slug: 'pro',
        name: 'Pro',
        components: [
            ['type' => 'FlatFee', 'config' => ['amount_usd' => 49.0]],
            ['type' => 'PerUnit', 'config' => ['strategy' => 'linear', 'provider_cost_usd' => 2.0, 'markup' => 3.0]],
        ],
    );

    $bill = $plan->compose(app(BillComposer::class), BillingPeriod::fromString('2026-07'));

    expect($bill->lineItems)->toHaveCount(2)
        ->and($bill->lineItems[0]->componentType)->toBe('FlatFee')
        ->and($bill->lineItems[0]->amountUsd)->toBe(49.0)
        ->and($bill->lineItems[1]->amountUsd)->toBe(6.0) // 2.0 provider cost x 3.0 markup
        ->and($bill->totalUsd)->toBe(55.0);
});

it('lets a subscription override change a component rate without cloning the Plan', function () {
    $plan = new Plan(
        slug: 'pro',
        name: 'Pro',
        components: [['type' => 'PerUnit', 'config' => ['provider_cost_usd' => 2.0, 'markup' => 3.0]]],
    );
    $composer = app(BillComposer::class);
    $period = BillingPeriod::fromString('2026-07');

    $base = $plan->compose($composer, $period);
    $overridden = $plan->compose($composer, $period, ['PerUnit' => ['markup' => 5.0]]);

    expect($base->totalUsd)->toBe(6.0)
        ->and($overridden->totalUsd)->toBe(10.0); // same Plan, markup overridden 3 -> 5
});

it('rejects an unregistered component type', function () {
    $plan = new Plan(slug: 'x', name: 'X', components: [['type' => 'Nope']]);

    expect(fn () => $plan->compose(app(BillComposer::class), BillingPeriod::current()))
        ->toThrow(RuntimeException::class);
});

it('registers linear as the only launch pricing strategy', function () {
    expect(app(PricingStrategyRegistry::class)->names())->toBe(['linear']);
});

it('validates the billing period format', function () {
    expect(fn () => BillingPeriod::fromString('2026-13'))->toThrow(InvalidArgumentException::class)
        ->and((string) BillingPeriod::fromString('2026-07'))->toBe('2026-07');
});
