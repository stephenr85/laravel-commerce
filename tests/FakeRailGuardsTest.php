<?php

use Rushing\Commerce\Exceptions\FakeDriverInProduction;
use Rushing\Commerce\Exceptions\StripeDisabledOnFakeRail;
use Rushing\Commerce\MoneyIn;
use Rushing\Commerce\MoneyInManager;
use Rushing\Commerce\Stripe\FakeRailStripeHttpClient;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;

/*
 * The fake rail's two guards (purchase-safe-fixes 04; purchase-walkthrough FINDINGS gap 7, sidebar P-A4).
 *
 * 1. `fake` never collects in production. A host that leaves COMMERCE_DRIVER unset gets `fake`, and a fake "Succeeded"
 *    payment would grant whatever was bought for free. Resolving the fake driver in production throws, before any
 *    Payment exists.
 * 2. Under `fake`, nothing reaches api.stripe.com. Cashier and every other Stripe SDK caller send through stripe-php's
 *    HTTP client, which `Http::fake()` cannot see; the package installs a client that refuses every request while the
 *    rail is `fake`, so a money path still calling Stripe directly fails loudly instead of leaking.
 */

afterEach(function () {
    ApiRequestor::setHttpClient(null);
    app()['env'] = 'testing';
});

it('refuses to resolve the fake driver in production, before any payment is recorded', function () {
    config(['commerce.driver' => 'fake']);
    app()['env'] = 'production';
    app(MoneyInManager::class)->forgetDrivers();

    expect(fn () => app(MoneyIn::class)->place(testOrder(minorUnits: 900)))
        ->toThrow(FakeDriverInProduction::class, 'fake payment driver in production');
});

it('refuses the fake driver by name in production too', function () {
    app()['env'] = 'production';
    app(MoneyInManager::class)->forgetDrivers();

    expect(fn () => app(MoneyInManager::class)->driver('fake'))->toThrow(FakeDriverInProduction::class);
});

it('still resolves the fake driver outside production', function () {
    app(MoneyInManager::class)->forgetDrivers();

    expect(app(MoneyInManager::class)->driver('fake')->name())->toBe('fake');
});

it('refuses every Stripe SDK request while the rail is fake, so nothing reaches api.stripe.com', function () {
    config(['commerce.driver' => 'fake']);
    $sent = [];
    $network = recordingStripeTransport($sent);
    ApiRequestor::setHttpClient(new FakeRailStripeHttpClient($network));

    expect(fn () => (new StripeClient('sk_test_fake'))->customers->create(['email' => 'ada@example.test']))
        ->toThrow(StripeDisabledOnFakeRail::class, 'fake rail: Stripe disabled (POST https://api.stripe.com/v1/customers)');

    expect($sent)->toBe([]);
});

it('passes Stripe SDK requests through once the rail is not fake', function () {
    config(['commerce.driver' => 'stripe']);
    $sent = [];
    ApiRequestor::setHttpClient(new FakeRailStripeHttpClient(recordingStripeTransport($sent)));

    (new StripeClient('sk_test_fake'))->customers->create(['email' => 'ada@example.test']);

    expect($sent)->toBe(['POST https://api.stripe.com/v1/customers']);
});

it('installs the guard at boot when the rail is fake', function () {
    $installed = (new ReflectionProperty(ApiRequestor::class, '_httpClient'))->getValue();

    expect(config('commerce.driver'))->toBe('fake')
        ->and($installed)->toBeInstanceOf(FakeRailStripeHttpClient::class);
})->skip(fn () => ! property_exists(ApiRequestor::class, '_httpClient'), 'stripe-php keeps its client elsewhere');

/** A stripe-php transport that records what would have been sent and answers with an empty object. */
function recordingStripeTransport(array &$sent): ClientInterface
{
    return new class($sent) implements ClientInterface
    {
        public function __construct(private array &$sent) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1')
        {
            $this->sent[] = strtoupper($method).' '.$absUrl;

            return ['{"id":"cus_test","object":"customer"}', 200, []];
        }
    };
}
