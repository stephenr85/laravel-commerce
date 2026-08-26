<?php

namespace Rushing\Commerce\Acp\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Data;

/**
 * The ACP `payment_data` object — the delegate payment an agent hands over on complete
 * (api-surface-coherence ticket 64). The input twin of {@see DelegatePayment}, spelled with the
 * protocol's own snake_case keys so the published schema is what a compliant agent sends.
 */
class CheckoutPaymentDataInput extends Data
{
    public function __construct(
        #[Description('Token that represents the payment method. Opaque sell-side: it is vaulted with the PSP by the buyer agent and verified at charge time, never stored as a payment-method identity here.')]
        public string $token,
        #[Description('String value representing the payment processor.')]
        public string $provider,
        #[Description('Optional billing address associated with the payment method, used for address verification and tax.')]
        public ?CheckoutBillingAddressInput $billing_address = null,
    ) {}

    /** The engine-facing delegate payment this object describes. */
    public function toDelegatePayment(): DelegatePayment
    {
        return new DelegatePayment(
            token: $this->token,
            provider: $this->provider,
            billingAddress: $this->billing_address?->toBillingAddress(),
        );
    }
}
