<?php

use Rushing\Commerce\Contracts\SyncsPlanCatalog;
use Rushing\Commerce\Data\CatalogPlan;
use Rushing\Commerce\Data\Merchant;
use Rushing\Commerce\Data\Money;
use Rushing\Commerce\Data\PlanCatalogRefs;
use Rushing\Commerce\MoneyInManager;
use Rushing\Commerce\Tests\Support\FakeStripeCatalog;
use Stripe\ApiRequestor;

/*
 * owner-review-20261102 O2: the plan fee stays the price of record; a rail that keeps a catalog mirrors it. Stripe
 * keeps one Product per plan and immutable Prices, so a fee change mints a new Price, moves the lookup key and
 * archives the old one. Nothing here touches a subscription: existing subscribers stay on the Price they bought.
 */

beforeEach(function () {
    config()->set('commerce.driver', 'stripe');
    config()->set('commerce.stripe.secret', 'sk_test_fake');
    $this->stripe = new FakeStripeCatalog;
    ApiRequestor::setHttpClient($this->stripe);
    $this->catalog = app(MoneyInManager::class)->driver('stripe');
    $this->merchant = new Merchant(id: 'default');
});

function o2Plan(?float $monthly = 99.0, ?float $annualMonthly = 79.0, string $slug = 'starter', string $name = 'Starter', ?string $description = 'For small teams'): CatalogPlan
{
    return CatalogPlan::fromFee(
        planId: 'plan-uuid-1',
        slug: $slug,
        name: $name,
        description: $description,
        monthlyPriceUsd: $monthly,
        annualMonthlyPriceUsd: $annualMonthly,
    );
}

it('is a capability only the stripe rail has: fake and none keep no catalog', function () {
    $manager = app(MoneyInManager::class);

    expect($manager->driver('stripe'))->toBeInstanceOf(SyncsPlanCatalog::class)
        ->and($manager->driver('fake'))->not->toBeInstanceOf(SyncsPlanCatalog::class)
        ->and($manager->driver('none'))->not->toBeInstanceOf(SyncsPlanCatalog::class);
});

it('prices the annual fee as a yearly Price of twelve annual-monthly payments', function () {
    $plan = o2Plan(monthly: 99.0, annualMonthly: 79.0);

    expect($plan->monthly)->toEqual(Money::of(9900))
        ->and($plan->annual)->toEqual(Money::of(94800))
        ->and(o2Plan(monthly: 0.0, annualMonthly: null)->monthly)->toBeNull()
        ->and(o2Plan(monthly: 0.0, annualMonthly: null)->annual)->toBeNull()
        ->and(o2Plan(monthly: 0.0, annualMonthly: null)->hasFee())->toBeFalse();
});

it('creates one Product and a monthly and an annual Price on the first sync', function () {
    $refs = $this->catalog->syncPlan(o2Plan(), $this->merchant);

    expect($this->stripe->products)->toHaveCount(1);
    $product = $this->stripe->products[$refs->productId];
    expect($product['metadata'])->toBe(['plan_id' => 'plan-uuid-1', 'plan_slug' => 'starter'])
        ->and($product['name'])->toBe('Starter')
        ->and($product['description'])->toBe('For small teams');

    $monthly = $this->stripe->prices[$refs->monthlyPriceId];
    $annual = $this->stripe->prices[$refs->annualPriceId];
    expect($monthly)->toMatchArray(['product' => $refs->productId, 'currency' => 'usd', 'unit_amount' => 9900, 'lookup_key' => 'plan:starter:monthly', 'active' => true])
        ->and($monthly['recurring']['interval'])->toBe('month')
        ->and($annual)->toMatchArray(['unit_amount' => 94800, 'lookup_key' => 'plan:starter:annual'])
        ->and($annual['recurring']['interval'])->toBe('year');

    // Every Price is created with transfer_lookup_key, so a re-mint can take the key from its predecessor.
    $priceCreates = array_values(array_filter($this->stripe->requests, fn ($r) => $r['method'] === 'post' && $r['path'] === '/v1/prices'));
    expect($priceCreates)->toHaveCount(2)
        ->and(array_column(array_column($priceCreates, 'params'), 'transfer_lookup_key'))->toBe(['true', 'true']);

    expect($refs->monthlyAmount)->toBe(9900)
        ->and($refs->annualAmount)->toBe(94800)
        ->and($refs->currency)->toBe('usd')
        ->and($refs->archivedPriceIds)->toBe([]);
});

it('is idempotent: re-syncing an unchanged fee writes nothing to Stripe', function () {
    $refs = $this->catalog->syncPlan(o2Plan(), $this->merchant);
    $this->stripe->requests = [];

    $again = $this->catalog->syncPlan(o2Plan(), $this->merchant, $refs);

    expect($this->stripe->writes())->toBe([])
        ->and(array_diff_key($again->toBag(), ['synced_at' => 0]))->toEqual(array_diff_key($refs->toBag(), ['synced_at' => 0]))
        ->and($this->stripe->prices)->toHaveCount(2);
});

it('recovers a lost write-back from Stripe itself instead of minting duplicates', function () {
    $this->catalog->syncPlan(o2Plan(), $this->merchant);
    $this->stripe->requests = [];

    // No recorded refs (the job died before saving them): the Product is found by metadata.plan_id and each Price by
    // its lookup key.
    $refs = $this->catalog->syncPlan(o2Plan(), $this->merchant, null);

    expect($this->stripe->writes())->toBe([])
        ->and($this->stripe->products)->toHaveCount(1)
        ->and($this->stripe->prices)->toHaveCount(2)
        ->and($refs->monthlyPriceId)->not->toBeNull();
});

it('retries a first sync that lost its write-back while Search lags without minting a second Product or Price', function () {
    $this->stripe->searchLags = true;
    $this->catalog->syncPlan(o2Plan(), $this->merchant);

    // The job died before recording anything; the retry cannot see the Product through Search yet.
    $retry = $this->catalog->syncPlan(o2Plan(), $this->merchant, null);

    expect($this->stripe->products)->toHaveCount(1)
        ->and($this->stripe->prices)->toHaveCount(2)
        ->and($retry->productId)->toBe(array_key_first($this->stripe->products));
    foreach ($this->stripe->creates() as $create) {
        expect($create['idempotency'])->toStartWith('plan-catalog:default:plan-uuid-1:');
    }
});

it('retries a fee change that lost its write-back without minting a duplicate Price', function () {
    $before = $this->catalog->syncPlan(o2Plan(monthly: 99.0), $this->merchant);
    $after = $this->catalog->syncPlan(o2Plan(monthly: 129.0), $this->merchant, $before);

    // The worker died after Stripe changed and before the refs were saved: the retry sees the ORIGINAL refs.
    $retry = $this->catalog->syncPlan(o2Plan(monthly: 129.0), $this->merchant, $before);

    expect($this->stripe->prices)->toHaveCount(3)
        ->and($retry->monthlyPriceId)->toBe($after->monthlyPriceId)
        ->and($this->stripe->prices[$after->monthlyPriceId])->toMatchArray(['active' => true, 'lookup_key' => 'plan:starter:monthly'])
        ->and($retry->claims($before->monthlyPriceId))->toBeTrue();
});

it('offers a withdrawn fee again as a new active Price, never the archived one an idempotent replay would return', function () {
    $paid = $this->catalog->syncPlan(o2Plan(monthly: 99.0, annualMonthly: null), $this->merchant);
    $free = $this->catalog->syncPlan(o2Plan(monthly: 0.0, annualMonthly: null), $this->merchant, $paid);

    $again = $this->catalog->syncPlan(o2Plan(monthly: 99.0, annualMonthly: null), $this->merchant, $free);

    expect($again->monthlyPriceId)->not->toBe($paid->monthlyPriceId)
        ->and($this->stripe->prices[$again->monthlyPriceId])->toMatchArray(['active' => true, 'unit_amount' => 9900, 'lookup_key' => 'plan:starter:monthly'])
        ->and($this->stripe->prices[$paid->monthlyPriceId]['active'])->toBeFalse()
        ->and($again->claims($paid->monthlyPriceId))->toBeTrue();
});

it('mints a new Price on a fee change, moves the lookup key and archives the old Price', function () {
    $before = $this->catalog->syncPlan(o2Plan(monthly: 99.0), $this->merchant);
    $this->stripe->requests = [];

    $after = $this->catalog->syncPlan(o2Plan(monthly: 129.0), $this->merchant, $before);

    expect($after->monthlyPriceId)->not->toBe($before->monthlyPriceId)
        ->and($after->annualPriceId)->toBe($before->annualPriceId)
        ->and($after->monthlyAmount)->toBe(12900)
        ->and($after->archivedPriceIds)->toBe([$before->monthlyPriceId]);

    expect($this->stripe->prices[$after->monthlyPriceId])->toMatchArray(['unit_amount' => 12900, 'active' => true, 'lookup_key' => 'plan:starter:monthly'])
        ->and($this->stripe->prices[$before->monthlyPriceId])->toMatchArray(['active' => false, 'lookup_key' => null]);

    // One new Price, one archive; no Product rewrite; and never a subscription call: subscribers stay where they are.
    expect($this->stripe->writes())->toBe(['POST /v1/prices', 'POST /v1/prices/'.$before->monthlyPriceId]);
    expect(array_filter($this->stripe->requests, fn ($r) => str_contains($r['path'], 'subscription')))->toBe([]);
});

it('claims every Price it ever recorded, so a subscriber on an archived Price still maps back to the plan', function () {
    $before = $this->catalog->syncPlan(o2Plan(monthly: 99.0), $this->merchant);
    $after = $this->catalog->syncPlan(o2Plan(monthly: 129.0), $this->merchant, $before);

    expect($after->claims($before->monthlyPriceId))->toBeTrue()
        ->and($after->claims($after->monthlyPriceId))->toBeTrue()
        ->and($after->claims($after->annualPriceId))->toBeTrue()
        ->and($after->claims('price_someone_else'))->toBeFalse();
});

it('updates the Product name and description in place without touching Prices', function () {
    $refs = $this->catalog->syncPlan(o2Plan(), $this->merchant);
    $this->stripe->requests = [];

    $again = $this->catalog->syncPlan(o2Plan(name: 'Starter Plus', description: null), $this->merchant, $refs);

    expect($this->stripe->writes())->toBe(['POST /v1/products/'.$refs->productId])
        ->and($this->stripe->products[$refs->productId]['name'])->toBe('Starter Plus')
        ->and($this->stripe->products[$refs->productId]['description'])->toBeNull()
        ->and($again->productId)->toBe($refs->productId);
});

it('moves the lookup key in place when only the slug changes', function () {
    $refs = $this->catalog->syncPlan(o2Plan(slug: 'starter'), $this->merchant);
    $this->stripe->requests = [];

    $again = $this->catalog->syncPlan(o2Plan(slug: 'starter-2026'), $this->merchant, $refs);

    expect($again->monthlyPriceId)->toBe($refs->monthlyPriceId)
        ->and($this->stripe->prices[$refs->monthlyPriceId]['lookup_key'])->toBe('plan:starter-2026:monthly')
        ->and($this->stripe->prices)->toHaveCount(2)
        ->and(array_filter($this->stripe->writes(), fn ($w) => $w === 'POST /v1/prices'))->toBe([]);
});

it('archives the Prices of a fee that is removed and records none', function () {
    $refs = $this->catalog->syncPlan(o2Plan(), $this->merchant);

    $free = $this->catalog->syncPlan(o2Plan(monthly: 0.0, annualMonthly: 0.0), $this->merchant, $refs);

    expect($free->monthlyPriceId)->toBeNull()
        ->and($free->annualPriceId)->toBeNull()
        ->and($free->productId)->toBe($refs->productId)
        ->and($this->stripe->prices[$refs->monthlyPriceId]['active'])->toBeFalse()
        ->and($this->stripe->prices[$refs->annualPriceId]['active'])->toBeFalse()
        ->and($free->claims($refs->monthlyPriceId))->toBeTrue();
});

it('creates no Product for a plan that has never had a fee', function () {
    $refs = $this->catalog->syncPlan(o2Plan(monthly: null, annualMonthly: null), $this->merchant);

    expect($this->stripe->writes())->toBe([])
        ->and($refs->productId)->toBeNull();
});

it('retires a plan: archives its Prices and its Product', function () {
    $refs = $this->catalog->syncPlan(o2Plan(), $this->merchant);

    $retired = $this->catalog->retirePlan($refs, $this->merchant);

    expect($this->stripe->products[$refs->productId]['active'])->toBeFalse()
        ->and($this->stripe->prices[$refs->monthlyPriceId]['active'])->toBeFalse()
        ->and($this->stripe->prices[$refs->annualPriceId]['active'])->toBeFalse()
        ->and($retired->monthlyPriceId)->toBeNull()
        ->and($retired->claims($refs->annualPriceId))->toBeTrue();
});

it('round-trips the refs bag a host stores', function () {
    $refs = new PlanCatalogRefs(
        productId: 'prod_1',
        monthlyPriceId: 'price_m',
        annualPriceId: null,
        monthlyAmount: 9900,
        annualAmount: null,
        currency: 'usd',
        archivedPriceIds: ['price_old'],
        syncedAt: '2026-11-06T00:00:00Z',
    );

    expect($refs->toBag())->toBe([
        'product_id' => 'prod_1',
        'prices' => ['monthly' => 'price_m', 'annual' => null],
        'amounts' => ['monthly' => 9900, 'annual' => null],
        'currency' => 'usd',
        'archived_prices' => ['price_old'],
        'synced_at' => '2026-11-06T00:00:00Z',
    ])->and(PlanCatalogRefs::fromBag($refs->toBag()))->toEqual($refs)
        ->and(PlanCatalogRefs::fromBag([]))->toEqual(new PlanCatalogRefs);
});
