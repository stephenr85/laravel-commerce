<?php

namespace Rushing\Commerce\Acp\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The ACP **create checkout session** request body.
 *
 * This documents *someone else's* wire contract — the Agentic Commerce Protocol, not ours — so the
 * property prose says what ACP says a field is, and the field names are fixed by that spec rather than
 * chosen here (api-surface-coherence ticket 64). That makes ticket 27's spell-the-property-as-the-wire-key
 * rule load-bearing: an agent implementing ACP reads the published schema and sends exactly these keys,
 * so a camelCase property mapped onto a snake_case wire key would publish a body no compliant agent
 * sends (ticket 54).
 *
 * ## `id` versus `item_ref`
 *
 * The spec names the per-item catalog reference **`id`** ("Id of a piece of merchandise that can be
 * purchased"). This implementation shipped it as `item_ref` and validated `items.*.item_ref` as
 * required, so a spec-conformant agent's request was rejected 422 — an interop defect, not a naming
 * preference. The declaration therefore leads with the spec's `id`, and `item_ref` is kept as an
 * accepted alias so nothing that worked before stops working. The rule change is strictly widening:
 * one of the two must be present, where previously `item_ref` was mandatory.
 */
class CheckoutSessionCreateInput extends Data
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function __construct(
        #[Description('The initial list of items to initiate the checkout session. Each entry carries `id` — the id of a piece of merchandise that can be purchased — and an optional `quantity` (the quantity of the item for fulfillment, defaulting to 1). This implementation also accepts the legacy key `item_ref` in place of `id`.')]
        public array $items,
        #[Description('ISO 4217 currency code the session prices in. A protocol extension, not part of the ACP request schema; omitted means the merchant default.')]
        public string|Optional|null $currency = null,
        #[Description('Free-text reason the agent gives for this action, recorded on the session provenance alongside the X-Agent-* headers. Read from the body only as a fallback when the X-Agent-Reason header is absent.')]
        public string|Optional|null $reason = null,
    ) {}

    /**
     * Transcribed from the inline validator this class replaced, with one deliberate widening: the
     * per-item catalog reference may arrive under the spec's `id` or the legacy `item_ref`, and
     * exactly one of the two is required per line. Everything the old validator accepted is still
     * accepted.
     *
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required_without:items.*.item_ref', 'string'],
            'items.*.item_ref' => ['required_without:items.*.id', 'string'],
            'items.*.quantity' => ['sometimes', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'reason' => ['sometimes', 'string'],
        ];
    }

    /**
     * The line items in the shape {@see \Rushing\Commerce\Acp\AgenticCheckout::create()} reads —
     * `item_ref` keyed, whichever spelling arrived. Normalising here keeps the engine ignorant of the
     * protocol's naming history.
     *
     * @return array<int, array<string, mixed>>
     */
    public function engineItems(): array
    {
        return array_map(static function (array $item): array {
            $item['item_ref'] = (string) ($item['id'] ?? $item['item_ref'] ?? '');

            return $item;
        }, $this->items);
    }
}
