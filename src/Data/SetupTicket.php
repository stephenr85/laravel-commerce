<?php

namespace Rushing\Commerce\Data;

use Rushing\LaravelDataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * The handle a host needs to attach a card without charging — the client secret
 * the browser confirms against, plus the provider's setup reference. The card
 * itself is tokenized client-side; the server only ever holds this ticket and,
 * afterward, the resulting payment-method reference.
 */
class SetupTicket extends Data implements SchemaIdentity
{
    public function __construct(
        public string $clientSecret,
        public string $providerRef,
        public string $merchantId,
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/setup-ticket';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
