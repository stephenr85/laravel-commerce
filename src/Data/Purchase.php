<?php

declare(strict_types=1);

namespace Rushing\Commerce\Data;

use Rushing\Commerce\Enums\PurchaseKind;
use Rushing\LaravelDataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * The durable record of what money bought — a perpetual unlock, a subscription
 * term, or a Credit top-up — carrying the Payment and Receipt that back it. The
 * outcome of a paid Order. What access this grants is host-mapped, not owned here.
 */
class Purchase extends Data implements SchemaIdentity
{
    public function __construct(
        public string $id,
        public string $orderId,
        public PurchaseKind $kind,
        public Payment $payment,
        public Receipt $receipt,
        public ?string $beneficiaryId = null,
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
