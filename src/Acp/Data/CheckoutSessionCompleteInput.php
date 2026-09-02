<?php

namespace Rushing\Commerce\Acp\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The ACP **complete checkout session** request body (api-surface-coherence ticket 64).
 *
 * `payment_data` is the spec's own field name and nests {@see DelegatePayment}'s three keys verbatim —
 * `token`, `provider`, `billing_address`. The wire is snake_case throughout and PINNED by `#[MapInputName]`
 * (api-surface-coherence 100's shape): a host whose global `name_mapping_strategy.input` is CamelCaseMapper
 * — the flagship — would otherwise re-spell the key as `paymentData` and 422 every spec-conformant agent
 * (measured 2026-09-01 by agentic-commerce-sell-side 10: green package suite, red host). A compliant agent
 * reads the published schema and sends exactly what ACP defines.
 */
class CheckoutSessionCompleteInput extends Data
{
    public function __construct(
        #[Description('Payment data used to complete the checkout session.')]
        #[MapInputName('payment_data')]
        public CheckoutPaymentDataInput $paymentData,
        #[Description('Free-text reason the agent gives for this action, recorded on the order provenance. Read from the body only as a fallback when the X-Agent-Reason header is absent.')]
        public string|Optional|null $reason = null,
    ) {}

    /**
     * Transcribed verbatim from the inline validator this class replaced — same two required keys,
     * nothing added, nothing relaxed. `billing_address` was never validated and still is not: every
     * field of `Rushing\Commerce\Data\BillingAddress` is optional.
     *
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'payment_data.token' => ['required', 'string'],
            'payment_data.provider' => ['required', 'string'],
            'reason' => ['sometimes', 'string'],
        ];
    }

    /** The delegate payment this body carries, billing address included when one was sent. */
    public function payment(): DelegatePayment
    {
        return $this->paymentData->toDelegatePayment();
    }
}
