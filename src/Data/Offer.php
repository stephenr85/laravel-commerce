<?php

namespace Rushing\Commerce\Data;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * Something a Merchant makes available for purchase, at a Price, with a Cadence.
 *
 * A **fulfillment descriptor** rides along, brand-blind: `$kind` is an opaque grant kind (the string
 * a host's fulfillment maps a purchase to — e.g. `credit-topup`), fed to `Order::for(kind:)`; `$grant`
 * is an opaque parameter bag the engine carries through untouched (e.g. `['credits' => 1000]`). The
 * engine never learns what a "credit" means — only that a completed purchase is of `$kind` with these
 * `$grant` params, which a host-bound listener interprets. Both default empty so a bare Offer stays
 * valid; nothing below the seam consumes them until fulfillment carry-through (commerce issue 06).
 */
class Offer extends Data implements SchemaIdentity
{
    /**
     * @param  array<string, mixed>  $grant
     */
    public function __construct(
        public string $slug,
        public string $name,
        public Price $price,
        public ?string $kind = null,
        public array $grant = [],
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/offer';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
