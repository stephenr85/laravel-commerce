<?php

namespace Rushing\Commerce\Acp\Data;

use Rushing\Commerce\Data\BillingAddress;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * The `payment_data` an ACP agent hands over on `complete` — a **delegate payment
 * token** already authorized upstream (the buyer's agent vaulted the card with the
 * PSP; sell-side we verify the *token*, never a spend mandate). The token is
 * opaque: it rides an Order as `paymentMethodRef` through the money-in driver seam,
 * which the satellite charges as merchant-of-record. Sell-side keeps no Stripe
 * `pm_`/`cus_` — payment-method identity stays host-side (ADR-0131).
 */
class DelegatePayment extends Data implements SchemaIdentity
{
    public function __construct(
        public string $token,
        public string $provider,
        public ?BillingAddress $billingAddress = null,
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/acp/delegate-payment';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
