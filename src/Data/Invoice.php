<?php

namespace Rushing\Commerce\Data;

use Illuminate\Support\Str;
use Rushing\Commerce\Enums\InvoiceStatus;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * A statement of what is owed, before payment — the collectible projection of a
 * finalized Bill. It preserves the Bill's fractional-USD lines for provenance but
 * carries a single chargeable Money total: the sub-cent metering lines round once,
 * here, into a Money amount (ADR-0002). The platform's Bill keeps the authoritative
 * pricing/rollup math; the Invoice never recomputes it.
 */
class Invoice extends Data implements SchemaIdentity
{
    /**
     * @param  array<int, BillingLineItem>  $lineItems
     */
    public function __construct(
        public string $id,
        public Customer $customer,
        #[DataCollectionOf(BillingLineItem::class)]
        public array $lineItems,
        public Money $total,
        public InvoiceStatus $status = InvoiceStatus::Open,
        public ?string $billReference = null,
    ) {}

    public static function fromComposedBill(
        ComposedBill $bill,
        Customer $customer,
        string $currency = 'USD',
        ?string $billReference = null,
    ): self {
        return new self(
            id: (string) Str::uuid(),
            customer: $customer,
            lineItems: $bill->lineItems,
            total: Money::fromUsd($bill->totalUsd, $currency),
            status: InvoiceStatus::Open,
            billReference: $billReference,
        );
    }

    public function withStatus(InvoiceStatus $status): self
    {
        return new self(
            id: $this->id,
            customer: $this->customer,
            lineItems: $this->lineItems,
            total: $this->total,
            status: $status,
            billReference: $this->billReference,
        );
    }

    public static function schemaName(): string
    {
        return 'commerce/invoice';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
