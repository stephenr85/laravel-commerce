<?php

namespace Rushing\Commerce\Data;

use Rushing\Commerce\Enums\PurchaseKind;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * The durable record of what money bought — a perpetual unlock, a subscription
 * term, or a Credit top-up — carrying the Payment and Receipt that back it. The
 * outcome of a paid Order. What access this grants is host-mapped, not owned here.
 *
 * `$grant` is the opaque fulfillment bag carried from the offer's descriptor (issue 05) plus the
 * purchased line context — the engine never interprets it; a host listener does (fund a wallet, dispatch
 * a Circuit Run, etc). Empty for a bare Purchase. `PurchaseCompleted` fires with this before any order is
 * recorded, so a listener that needs the offer ref / quantity finds them here, not in a stored order.
 */
class Purchase extends Data implements SchemaIdentity
{
    /**
     * @param  array<string, mixed>  $grant
     */
    public function __construct(
        public string $id,
        public string $orderId,
        public PurchaseKind $kind,
        public Payment $payment,
        public Receipt $receipt,
        public ?string $beneficiaryId = null,
        public array $grant = [],
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/purchase';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
