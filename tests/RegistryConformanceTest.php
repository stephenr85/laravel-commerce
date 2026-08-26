<?php

use Rushing\Commerce\Acp\Feed\AcpFeedProjector;
use Rushing\Commerce\Acp\Feed\AcpFeedRegistry;
use Rushing\Commerce\Acp\Feed\AttributeAcpFeedProjector;
use Rushing\Commerce\Billing\ComponentRegistry;
use Rushing\Commerce\Billing\Pricing\LinearStrategy;
use Rushing\Commerce\Billing\Pricing\PricingStrategyRegistry;
use Rushing\Commerce\Drivers\FakeDriver;
use Rushing\Commerce\MoneyInManager;
use Rushing\Commerce\Tests\Fixtures\OfferFeedFixture;
use Rushing\Commerce\Tests\Support\FlatFeeComponent;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Spatie\LaravelData\Data;

/*
 * This package's four rows of the registry-kernel 38 sweep. One of them — MoneyInManager — is the
 * estate's only untested archetype **e**, the `extends Manager` foreign base; its recipe is
 * `Rushing\Graphine\Laravel\GraphStoreManager`, cited rather than re-derived.
 */

/*
 * Spine step 9. `RegistryIndex` is auto-resolvable, so a harness that does not boot
 * PopcornServiceProvider gets a FRESH index per make(): every describe() lands on a throwaway and the
 * assertions below would pass over an empty index (27 D3 / 37 finding 2). This must fail first.
 */
it('shares one RegistryIndex across the container', function () {
    expect(app(RegistryIndex::class))->toBe(app(RegistryIndex::class));
});

it('conforms to the kernel contract and describes all four roots', function () {
    expect(app(MoneyInManager::class))->toBeInstanceOf(Registry::class)
        ->and(app(ComponentRegistry::class))->toBeInstanceOf(Registry::class)
        ->and(app(PricingStrategyRegistry::class))->toBeInstanceOf(Registry::class)
        ->and(app(AcpFeedRegistry::class))->toBeInstanceOf(Registry::class);

    $keys = array_map(strval(...), app(RegistryIndex::class)->keys());

    expect($keys)->toContain('commerce.money-in.drivers')
        ->and($keys)->toContain('commerce.billing.components')
        ->and($keys)->toContain('commerce.billing.pricing-strategies')
        ->and($keys)->toContain('commerce.acp.feeds');
});

/*
 * ARCHETYPE e. The two shipped rails are REGISTERED rather than left to Manager's create*Driver()
 * convention — nothing can enumerate those without reflecting into the parent's protected
 * $customCreators, so a registry that reported only what a host extend()ed would report an empty root
 * while two drivers worked.
 */
it('reports both shipped money-in rails without reflecting into Manager', function () {
    expect(array_map(strval(...), app(MoneyInManager::class)->keys()))->toBe([
        'commerce.money-in.drivers.fake',
        'commerce.money-in.drivers.stripe',
    ]);
});

/*
 * An entry is the FACTORY, not the driver (10 D5). Enumerating this registry must not construct the
 * Stripe SDK to answer "what rails exist".
 */
it('stores a driver factory and keeps enumeration non-constructive', function () {
    $manager = app(MoneyInManager::class);

    expect($manager->resolve('fake'))->toBeInstanceOf(Closure::class)
        ->and($manager->driver('fake'))->toBeInstanceOf(FakeDriver::class);
});

it('routes Manager::extend() through the keyspace so a host driver becomes visible', function () {
    $manager = app(MoneyInManager::class)->extend('adyen', fn (): FakeDriver => new FakeDriver);

    expect($manager->has('adyen'))->toBeTrue()
        ->and($manager->driver('adyen'))->toBeInstanceOf(FakeDriver::class)
        ->and(array_map(strval(...), $manager->keys()))->toContain('commerce.money-in.drivers.adyen');
});

it('refuses a built driver where a factory is required', function () {
    expect(fn () => app(MoneyInManager::class)->register('eager', new FakeDriver))
        ->toThrow(InvalidArgumentException::class);
});

/*
 * The component vocabulary is PascalCase and it is PERSISTED in Plan rows, so it cannot be rekeyed.
 * `Key::fromClass()` derives the segment and the caller keeps its own spelling at every door.
 */
it('derives a lowercase key from the persisted PascalCase component type', function () {
    $registry = app(ComponentRegistry::class)->register('FlatFee', fn () => new FlatFeeComponent);

    expect(array_map(strval(...), $registry->keys()))->toContain('commerce.billing.components.flat-fee')
        ->and($registry->has('FlatFee'))->toBeTrue()
        ->and($registry->has('flat-fee'))->toBeTrue()
        ->and($registry->make('FlatFee'))->toBeInstanceOf(FlatFeeComponent::class)
        ->and($registry->resolve('FlatFee'))->toBeInstanceOf(Closure::class);
});

/*
 * The miss changes class from this package's bare RuntimeException to the kernel's RegistryMiss —
 * which EXTENDS RuntimeException, so the change is invisible to every catch in the estate. That
 * compatibility is exactly why the exception change is taken here and declined on AcpFeedRegistry.
 */
it('still throws a RuntimeException for an unregistered component type', function () {
    expect(fn () => app(ComponentRegistry::class)->make('NeverRegistered'))
        ->toThrow(RuntimeException::class);
});

it('keeps `linear` pre-registered and readable by name', function () {
    $registry = app(PricingStrategyRegistry::class);

    expect($registry->names())->toBe(['linear'])
        ->and($registry->resolve('linear'))->toBeInstanceOf(LinearStrategy::class)
        ->and(array_map(strval(...), $registry->keys()))
        ->toBe(['commerce.billing.pricing-strategies.linear']);
});

/*
 * A FQCN is not a Key — backslashes are outside the grammar. That is ticket 58's wall, and this row
 * clears it because the entries have a stable identity a key can be DERIVED from (sweep amendment 5):
 * the projector's own subject().
 */
it('derives the ACP feed key from the projector subject and keeps the one-argument call shape', function () {
    $registry = app(AcpFeedRegistry::class)->register(new AttributeAcpFeedProjector(OfferFeedFixture::class));

    expect(array_map(strval(...), $registry->keys()))->toBe(['commerce.acp.feeds.offer-feed-fixture'])
        ->and($registry->has(OfferFeedFixture::class))->toBeTrue()
        ->and($registry->has('offer-feed-fixture'))->toBeTrue();
});

/*
 * What the derivation costs, paid rather than ignored: two subjects sharing a basename would silently
 * supersede under OnDuplicate::Supersede. They throw instead.
 */
it('refuses two projectors whose subjects share a basename', function () {
    $registry = app(AcpFeedRegistry::class)->register(new AttributeAcpFeedProjector(OfferFeedFixture::class));

    $collider = new class implements AcpFeedProjector
    {
        public function subject(): string
        {
            return 'Some\Other\Namespace\OfferFeedFixture';
        }

        public function project(Data $data): array
        {
            return [];
        }
    };

    expect(fn () => $registry->register($collider))->toThrow(InvalidArgumentException::class);
});
