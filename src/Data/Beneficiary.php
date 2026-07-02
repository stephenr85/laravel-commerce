<?php

declare(strict_types=1);

namespace Rushing\Commerce\Data;

use Rushing\LaravelDataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * The party a Purchase is *for*, when not the Customer who paid. `partyId` is
 * null for a gift to someone with no account yet; `deliverTo` is an optional
 * host-owned delivery hint (an email, a handle) the engine never interprets.
 */
class Beneficiary extends Data implements SchemaIdentity
{
    public function __construct(
        public ?string $partyId = null,
        public ?string $deliverTo = null,
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/beneficiary';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
