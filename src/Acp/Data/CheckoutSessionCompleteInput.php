<?php

namespace Rushing\Commerce\Acp\Data;

use Rushing\Commerce\Data\BillingAddress;
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
    /**
     * @param  array<string, mixed>  $payment_data
     */
    public function __construct(
        #[Description('Payment data used to complete the checkout session: `token` (the token that represents the payment method), `provider` (a string value representing the payment processor), and an optional `billing_address` (the billing address associated with the payment method).')]
        public array $payment_data,
        #[Description('Free-text reason the agent gives for this action, recorded on the order provenance. Read from the body only as a fallback when the X-Agent-Reason header is absent.')]
        public string|Optional|null $reason = null,
    ) {}

    /**
     * Transcribed verbatim from the inline validator this class replaced — same two required keys,
     * nothing added, nothing relaxed. `billing_address` was never validated and still is not: it is
     * hydrated through {@see BillingAddress}, whose every field is optional.
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
        $address = $this->payment_data['billing_address'] ?? null;

        return new DelegatePayment(
            token: (string) $this->payment_data['token'],
            provider: (string) $this->payment_data['provider'],
            billingAddress: is_array($address) ? BillingAddress::from($address) : null,
        );
    }
}
