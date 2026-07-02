<?php

declare(strict_types=1);

namespace Rushing\Commerce\Data;

use Rushing\LaravelDataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * The party that pays. Identified by a host-owned key; name/email are optional
 * for a bearer/guest checkout (a gift buyer's recipient may have no account).
 */
class Customer extends Data implements SchemaIdentity
{
    public function __construct(
        public string $id,
        public ?string $name = null,
        public ?string $email = null,
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/customer';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
