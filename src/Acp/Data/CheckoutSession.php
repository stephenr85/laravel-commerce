<?php

namespace Rushing\Commerce\Acp\Data;

use Rushing\Commerce\Acp\Enums\CheckoutStatus;
use Rushing\Commerce\Data\Money;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * An ACP Agentic Checkout session — the protocol envelope an external agent
 * creates, reads, and completes. It carries the resolved line items, the running
 * total, the status, the driving agent's provenance, and (once completed) the
 * `OrderRef` pointing at the recorded minimal order. Neutral by construction: it
 * references catalog items only by opaque ref and never persists a payment secret.
 */
class CheckoutSession extends Data implements SchemaIdentity
{
    /**
     * @param  array<int, CheckoutLineItem>  $lineItems
     */
    public function __construct(
        public string $id,
        public CheckoutStatus $status,
        public string $currency,
        #[DataCollectionOf(CheckoutLineItem::class)]
        public array $lineItems,
        public Money $total,
        public ?AgentProvenance $provenance = null,
        public ?OrderRef $order = null,
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/acp/checkout-session';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
