<?php

use Rushing\Commerce\Budget\BudgetExceededException;
use Rushing\Commerce\Budget\BudgetGate;
use Rushing\Commerce\Enums\PurchaseKind;
use Rushing\Commerce\MoneyIn;
use Rushing\Commerce\Tests\Support\ArrayUsageMeter;
use Rushing\Commerce\Wallets;

it('credits a Wallet from a completed credit-topup Purchase', function () {
    // A completed Purchase converts Money -> Credit at a host rate; here 1c => 1 credit.
    $order = testOrder(minorUnits: 1000, kind: PurchaseKind::CreditTopup);
    $purchase = app(MoneyIn::class)->place($order);

    app(Wallets::class)->topUp('party_1', 'credits', 1000.0, $purchase->id);

    expect(app(Wallets::class)->creditedFor('party_1', 'credits'))->toBe(1000.0);
});

it('gates prepaid usage through the existing BudgetGate with no gate changes', function () {
    $meter = new ArrayUsageMeter;
    app(Wallets::class)->topUp('party_1', 'credits', 100.0);

    $gate = app(BudgetGate::class);
    $source = fn () => app(Wallets::class)->budgetSource('party_1', 'credits', $meter, warnFraction: 0.8);

    // Healthy: 10 of 100 consumed.
    $meter->set('party_1', 'credits', 10.0);
    $verdict = $gate->inspect($source());
    expect($verdict->allowed)->toBeTrue()
        ->and($verdict->warning)->toBeFalse()
        ->and($verdict->remaining)->toBe(90.0);

    // Warn: 85 of 100 consumed, past the 80% threshold but not exhausted.
    $meter->set('party_1', 'credits', 85.0);
    $verdict = $gate->inspect($source());
    expect($verdict->allowed)->toBeTrue()
        ->and($verdict->warning)->toBeTrue();

    // Deny: balance exhausted.
    $meter->set('party_1', 'credits', 100.0);
    $verdict = $gate->inspect($source());
    expect($verdict->allowed)->toBeFalse();
});

it('authorizes on headroom and throws when the balance is exhausted', function () {
    $meter = new ArrayUsageMeter;
    app(Wallets::class)->topUp('party_1', 'credits', 50.0);
    $source = app(Wallets::class)->budgetSource('party_1', 'credits', $meter);

    $meter->set('party_1', 'credits', 20.0);
    app(BudgetGate::class)->authorize($source);

    $meter->set('party_1', 'credits', 50.0);
    expect(fn () => app(BudgetGate::class)->authorize(
        app(Wallets::class)->budgetSource('party_1', 'credits', $meter)
    ))->toThrow(BudgetExceededException::class);
});

it('treats a Wallet with no cap concept as uncapped when the source is null', function () {
    expect(app(BudgetGate::class)->inspect(null)->uncapped)->toBeTrue()
        ->and(app(BudgetGate::class)->allows(null))->toBeTrue();
});

it('denominates the Wallet in a host-defined unit, not a forced currency', function () {
    $meter = new ArrayUsageMeter;
    app(Wallets::class)->topUp('artist_9', 'generations', 5.0);
    $meter->set('artist_9', 'generations', 2.0);

    $source = app(Wallets::class)->budgetSource('artist_9', 'generations', $meter);

    expect($source->balance())->toBe(3.0)
        ->and($source->assess()->cap)->toBe(5.0);
});
