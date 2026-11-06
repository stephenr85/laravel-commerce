<?php

use Illuminate\Support\Str;
use Rushing\Commerce\Contracts\MoneyInDriver;
use Rushing\Commerce\Contracts\SettlesFundingOnSetup;
use Rushing\Commerce\Data\Customer;
use Rushing\Commerce\Data\Money;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Data\Payment;
use Rushing\Commerce\Data\PaymentAction;
use Rushing\Commerce\Data\Purchase;
use Rushing\Commerce\Data\Receipt;
use Rushing\Commerce\Data\Refund;
use Rushing\Commerce\Drivers\FakeDriver;
use Rushing\Commerce\Drivers\NoneDriver;
use Rushing\Commerce\Drivers\StripeDriver;
use Rushing\Commerce\Enums\PaymentStatus;
use Rushing\Commerce\Enums\PurchaseKind;
use Rushing\Commerce\FundingPolicy;
use Rushing\Commerce\MoneyInManager;
use Rushing\Commerce\Stripe\StripeWebhookTranslator;

/*
 * owner-review-20261102 FUNDING (owner, 2026-11-06: "why isn't it just based on the driver?"): whether a plan's confirmed
 * card setup funds the plan by itself is the RAIL's policy, declared through the optional SettlesFundingOnSetup
 * capability and read through FundingPolicy against the ACTIVE rail. fake: a confirmed setup funds, never in production.
 * stripe: a succeeded SetupIntent that saved a card for off-session use funds. none: never. A rail that declares nothing
 * keeps BUY-04's rule (funding lands with the first charged Bill).
 */

function fundingSetup(string $driver, array $grant = [], int $minor = 0, PaymentStatus $status = PaymentStatus::Succeeded, PurchaseKind $kind = PurchaseKind::Subscription): Purchase
{
    $paymentId = (string) Str::uuid();

    return new Purchase(
        id: (string) Str::uuid(),
        orderId: 'order-1',
        kind: $kind,
        payment: new Payment(id: $paymentId, orderId: 'order-1', amount: Money::of($minor), status: $status, driver: $driver),
        receipt: new Receipt(id: (string) Str::uuid(), paymentId: $paymentId, orderId: 'order-1', amount: Money::of($minor)),
        beneficiaryId: 'tenant-x',
        grant: ['subscription_id' => 'sub-1'] + $grant,
    );
}

function inProduction(callable $callback): mixed
{
    $previous = app()['env'];
    app()['env'] = 'production';
    try {
        return $callback();
    } finally {
        app()['env'] = $previous;
    }
}

beforeEach(function () {
    config(['commerce.driver' => 'fake', 'commerce.custody' => null]);
    app(MoneyInManager::class)->forgetDrivers();
});

it('declares the capability on fake, stripe and none, each answering its own policy', function () {
    expect(new FakeDriver)->toBeInstanceOf(SettlesFundingOnSetup::class)
        ->and(app(StripeDriver::class))->toBeInstanceOf(SettlesFundingOnSetup::class)
        ->and(new NoneDriver)->toBeInstanceOf(SettlesFundingOnSetup::class);
});

it('fake: a confirmed card setup funds the plan, outside production only', function () {
    expect((new FakeDriver)->fundsOnSetup(fundingSetup('fake')))->toBeTrue()
        ->and(FundingPolicy::fundsOnSetup(fundingSetup('fake')))->toBeTrue()
        // Mirrors FakeDriverInProduction: the driver answers false there itself, and the active-rail read refuses too.
        ->and(inProduction(fn () => (new FakeDriver)->fundsOnSetup(fundingSetup('fake'))))->toBeFalse()
        ->and(inProduction(fn () => FundingPolicy::fundsOnSetup(fundingSetup('fake'))))->toBeFalse();
});

it('stripe: a succeeded SetupIntent funds only when it saved a card for off-session use', function () {
    $stripe = app(StripeDriver::class);

    expect($stripe->fundsOnSetup(fundingSetup('stripe', ['payment_method' => 'pm_1', 'usage' => 'off_session'])))->toBeTrue()
        ->and($stripe->fundsOnSetup(fundingSetup('stripe', ['payment_method' => 'pm_1', 'usage' => 'on_session'])))->toBeFalse()
        ->and($stripe->fundsOnSetup(fundingSetup('stripe', ['payment_method' => 'pm_1'])))->toBeFalse()
        ->and($stripe->fundsOnSetup(fundingSetup('stripe', ['payment_method' => '', 'usage' => 'off_session'])))->toBeFalse()
        // Another rail's setup is never this rail's to fund.
        ->and($stripe->fundsOnSetup(fundingSetup('fake', ['payment_method' => 'pm_1', 'usage' => 'off_session'])))->toBeFalse();

    config(['commerce.driver' => 'stripe']);
    app(MoneyInManager::class)->forgetDrivers();
    expect(FundingPolicy::fundsOnSetup(fundingSetup('stripe', ['payment_method' => 'pm_1', 'usage' => 'off_session'])))->toBeTrue();
});

it('none: never funds, and custody none answers for every rail', function () {
    expect((new NoneDriver)->fundsOnSetup(fundingSetup('none')))->toBeFalse();

    config(['commerce.custody' => 'none']);
    app(MoneyInManager::class)->forgetDrivers();
    expect(FundingPolicy::fundsOnSetup(fundingSetup('fake')))->toBeFalse()
        ->and(FundingPolicy::fundsOnSetup(fundingSetup('stripe', ['payment_method' => 'pm_1', 'usage' => 'off_session'])))->toBeFalse();
});

it('reads the ACTIVE rail: a setup from a rail that is not active funds nothing', function () {
    // fake is active; a stripe setup that would fund on stripe does not fund here.
    expect(FundingPolicy::fundsOnSetup(fundingSetup('stripe', ['payment_method' => 'pm_1', 'usage' => 'off_session'])))->toBeFalse();

    config(['commerce.driver' => 'stripe']);
    app(MoneyInManager::class)->forgetDrivers();
    expect(FundingPolicy::fundsOnSetup(fundingSetup('fake')))->toBeFalse();
});

it('funds only a confirmed setup: never a charge, a failed setup, or another kind of purchase', function () {
    expect(FundingPolicy::fundsOnSetup(fundingSetup('fake', minor: 9900)))->toBeFalse()
        ->and(FundingPolicy::fundsOnSetup(fundingSetup('fake', status: PaymentStatus::Failed)))->toBeFalse()
        ->and(FundingPolicy::fundsOnSetup(fundingSetup('fake', kind: PurchaseKind::CreditTopup)))->toBeFalse();
});

it('keeps BUY-04\'s rule on a rail that declares no policy: a setup funds nothing', function () {
    app(MoneyInManager::class)->extend('ledger', fn () => new class implements MoneyInDriver
    {
        public function name(): string
        {
            return 'ledger';
        }

        public function pay(Order $order): Payment
        {
            throw new RuntimeException('not reached');
        }

        public function refund(Payment $payment): Refund
        {
            throw new RuntimeException('not reached');
        }

        public function manage(Customer $customer, string $returnUrl): PaymentAction
        {
            throw new RuntimeException('not reached');
        }
    });
    config(['commerce.driver' => 'ledger']);

    expect(FundingPolicy::fundsOnSetup(fundingSetup('ledger')))->toBeFalse();
});

it('carries the SetupIntent\'s usage on the translated setup, so the stripe policy can read it', function () {
    $payload = ['type' => 'setup_intent.succeeded', 'data' => ['object' => [
        'id' => 'seti_1', 'object' => 'setup_intent', 'status' => 'succeeded', 'customer' => 'cus_1',
        'payment_method' => 'pm_1', 'usage' => 'off_session',
        'metadata' => ['kind' => 'subscription', 'party_ref' => 'tenant-x', 'order_reference' => 'sub-1'],
    ]]];

    $setup = (new StripeWebhookTranslator)->subscriptionSetup($payload);

    expect($setup->grant['usage'])->toBe('off_session')
        ->and(app(StripeDriver::class)->fundsOnSetup($setup))->toBeTrue();
});
