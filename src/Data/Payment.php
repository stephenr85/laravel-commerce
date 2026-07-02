<?php

declare(strict_types=1);

namespace Rushing\Commerce\Data;

use Rushing\Commerce\Enums\PaymentStatus;
use Rushing\LaravelDataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * The fact that money moved for an Order — amount, status, and the driver that
 * captured it. Provider capture mechanics stay behind the driver seam; only the
 * normalized outcome, an opaque `providerRef`, and the collecting Merchant surface
 * here. `merchantId` lets a reversal resolve the same account that captured the
 * Payment (the fake driver leaves it null).
 */
class Payment extends Data implements SchemaIdentity
{
    public function __construct(
        public string $id,
        public string $orderId,
        public Money $amount,
        public PaymentStatus $status,
        public string $driver,
        public ?string $providerRef = null,
        public ?string $merchantId = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === PaymentStatus::Succeeded;
    }

    public static function schemaName(): string
    {
        return 'commerce/payment';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
