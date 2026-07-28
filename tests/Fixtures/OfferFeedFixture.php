<?php

namespace Rushing\Commerce\Tests\Fixtures;

use Rushing\Commerce\Acp\Feed\AcpFeedProperty;
use Spatie\LaravelData\Data;

/**
 * A catalog offer annotated for the ACP product-feed projection. (In a real satellite the same DTO
 * also carries schema.org attributes for the JSON-LD projection — that half lives in the schema.org
 * package and is exercised app-side; here we test only the ACP feed half this package owns.)
 */
class OfferFeedFixture extends Data
{
    public function __construct(
        #[AcpFeedProperty('id')]
        public string $id,
        #[AcpFeedProperty('title')]
        public string $title,
        #[AcpFeedProperty('price')]
        public ?string $price = null,
        #[AcpFeedProperty('availability')]
        public ?string $availability = null,
        #[AcpFeedProperty('enable_checkout')]
        public bool $enableCheckout = true,
    ) {}
}
