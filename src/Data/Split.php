<?php

namespace Rushing\Commerce\Data;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * An optional per-Order/Payment revenue split, supplied entirely by the CALLER (ticket 06 of the
 * splicewire-marketplace build) — the engine itself never decides a take-rate, never resolves a
 * payee, and carries no opinion about what `payeeRef` means. `platformFee` is the amount the
 * Merchant keeps; the remainder of the Order/Payment total goes to whatever `payeeRef` identifies.
 *
 * Deliberately provider-neutral in NAME even though its only current consumer (StripeDriver)
 * resolves it to destination-charge parameters (`application_fee_amount` + `transfer_data.
 * destination`) below this DTO — see that driver's own docblock and `rushing/laravel-commerce`'s
 * ADR on the split-payment seam. A `null` `payeeRef` means "no split partner" (e.g. a first-party
 * sale where the Merchant already keeps everything); a driver that understands Split MUST treat
 * that case as a plain, non-split charge/refund.
 */
class Split extends Data implements SchemaIdentity
{
    public function __construct(
        public Money $platformFee,
        public ?string $payeeRef = null,
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/split';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
