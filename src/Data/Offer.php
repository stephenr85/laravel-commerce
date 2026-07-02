<?php

declare(strict_types=1);

namespace Rushing\Commerce\Data;

use Rushing\LaravelDataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * Something a Merchant makes available for purchase, at a Price, with a Cadence.
 */
class Offer extends Data implements SchemaIdentity
{
    public function __construct(
        public string $slug,
        public string $name,
        public Price $price,
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
