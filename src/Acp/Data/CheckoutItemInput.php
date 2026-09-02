<?php

namespace Rushing\Commerce\Acp\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * One line of an ACP **create checkout session** request — the input twin of
 * {@see CheckoutLineItem}, which is the *response* shape and carries priced, resolved fields a
 * caller never sends.
 *
 * Declared as its own class rather than left as an opaque `array` so the published schema keeps the
 * per-line fields, and the generated client keeps a typed line item instead of a bag
 * (api-surface-coherence ticket 64).
 */
class CheckoutItemInput extends Data
{
    public function __construct(
        #[Description('Id of a piece of merchandise that can be purchased. This is the ACP spec field; see `item_ref` for the legacy spelling this implementation also accepts.')]
        public ?string $id = null,
        #[Description('Deprecated alias for `id`, the spelling this implementation shipped before it was reconciled with the ACP spec. Send `id` in new integrations; exactly one of the two is required.')]
        #[MapInputName('item_ref')]
        public ?string $itemRef = null,
        #[Description('Quantity of the item for fulfillment. Defaults to 1.')]
        public ?int $quantity = null,
    ) {}

    /** The catalog reference, whichever of the two spellings arrived. */
    public function ref(): string
    {
        return (string) ($this->id ?? $this->itemRef ?? '');
    }
}
