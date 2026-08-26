<?php

namespace Rushing\Commerce\Acp\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The ACP **complete checkout session** request body (api-surface-coherence ticket 64).
 *
 * `payment_data` is the spec's own field name and nests {@see DelegatePayment}'s three keys verbatim —
 * `token`, `provider`, `billing_address`. The wire is snake_case throughout, so every property here is
 * spelled with the wire's key rather than mapped onto it (ticket 54): a compliant agent reads the
 * published schema and sends exactly what ACP defines.
 */
class CheckoutSessionCompleteInput extends Data
{
    public function __construct(
        #[Description('Payment data used to complete the checkout session.')]
        public CheckoutPaymentDataInput $payment_data,
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
        return $this->payment_data->toDelegatePayment();
    }
}
