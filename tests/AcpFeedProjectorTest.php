<?php

use Rushing\Commerce\Acp\Feed\AcpFeedRegistry;
use Rushing\Commerce\Acp\Feed\AttributeAcpFeedProjector;
use Rushing\Commerce\Tests\Fixtures\OfferFeedFixture;

function feedOffer(): OfferFeedFixture
{
    return new OfferFeedFixture(
        id: 'demo-offer',
        title: 'Demo Offer',
        price: '9.00 USD',
        availability: 'in_stock',
    );
}

it('projects a DTO to the ACP product-feed shape, omitting null fields', function () {
    $item = (new AttributeAcpFeedProjector(OfferFeedFixture::class))->project(feedOffer());

    expect($item)->toBe([
        'id' => 'demo-offer',
        'title' => 'Demo Offer',
        'price' => '9.00 USD',
        'availability' => 'in_stock',
        'enable_checkout' => true,
    ]);

    $bare = (new AttributeAcpFeedProjector(OfferFeedFixture::class))
        ->project(new OfferFeedFixture(id: 'x', title: 'X'));
    expect($bare)->toHaveKeys(['id', 'title', 'enable_checkout'])
        ->and($bare)->not->toHaveKey('price');
});

it('builds a products feed from a set of DTOs via the registry', function () {
    $registry = app(AcpFeedRegistry::class)
        ->register(new AttributeAcpFeedProjector(OfferFeedFixture::class));

    $products = $registry->feed([feedOffer(), new OfferFeedFixture(id: 'b', title: 'B')]);

    expect($products)->toHaveCount(2)
        ->and($products[0]['id'])->toBe('demo-offer')
        ->and($products[1]['id'])->toBe('b');
});

it('throws for a DTO class with no registered projector', function () {
    expect(fn () => app(AcpFeedRegistry::class)->project(feedOffer()))
        ->toThrow(InvalidArgumentException::class);
});
