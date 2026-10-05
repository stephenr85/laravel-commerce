<?php

namespace Rushing\Commerce\Data;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
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
        // The kind of party `id` names (a morph alias) when the caller knows it, so a host resolving the party by id
        // cannot mistake one kind for another (purchase-walkthrough BUY-02 part D, build.qa). Null: the host's default.
        public ?string $type = null,
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/customer';
    }

    /**
     * Version 2 (purchase-walkthrough BUY-02): gained `type` (the party kind), additive and nullable, so the v1 -> v2 step is the
     * identity on a stored payload. v1 stays frozen as written.
     */
    public static function schemaVersion(): int
    {
        return 2;
    }
}
