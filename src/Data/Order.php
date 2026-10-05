<?php

namespace Rushing\Commerce\Data;

use Illuminate\Support\Str;
use Rushing\Commerce\Enums\Cadence;
use Rushing\Commerce\Enums\PaymentActionKind;
use Rushing\Commerce\Enums\PurchaseKind;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * The envelope of a single money-in transaction a Customer places with a
 * Merchant — its line items, total, and cadence. The total is derived from the
 * lines at construction so the Order is always internally consistent.
 */
class Order extends Data implements SchemaIdentity
{
    /**
     * @param  array<int, LineItem>  $lineItems
     */
    public function __construct(
        public string $id,
        public Customer $customer,
        public string $currency,
        #[DataCollectionOf(LineItem::class)]
        public array $lineItems,
        public Cadence $cadence,
        public Money $total,
        public PurchaseKind $kind,
        public ?string $reference = null,
        public ?string $paymentMethodRef = null,
        public bool $savePaymentMethod = false,
        public bool $offSession = false,
        public ?BillingAddress $billingAddress = null,
        // The `paymentMethodRef` is a delegated/shared payment token (ACP sell-side):
        // chargeable directly on the merchant's own account with no customer of ours —
        // the buyer's agent vaulted it upstream. The driver skips the CustomerVault for it.
        public bool $delegated = false,
        // Ticket 06 (splicewire-marketplace build): an optional revenue split, entirely
        // caller-supplied — see {@see Split}'s own docblock. Null for every non-marketplace
        // Order (the overwhelming majority); a driver that understands Split resolves it to
        // provider-specific split-payment parameters below this DTO.
        public ?Split $split = null,
        // BUY-02 (C-11): ask the rail for a HOSTED leg (a provider checkout page or an embedded session) instead of a
        // direct charge. The Payment comes back RequiresAction carrying `action`; it settles through the provider's
        // webhook (or the fake rail's confirm page) into the same PurchaseCompleted. One-time Orders only.
        public ?PaymentActionKind $hosted = null,
        // Where the hosted leg returns the browser. Stripe substitutes `{CHECKOUT_SESSION_ID}` when present.
        public ?string $returnUrl = null,
    ) {}

    /**
     * @param  array<int, LineItem>  $lineItems
     */
    public static function for(
        Customer $customer,
        array $lineItems,
        Cadence $cadence = Cadence::OneTime,
        ?PurchaseKind $kind = null,
        ?string $currency = null,
        ?string $reference = null,
        ?string $paymentMethodRef = null,
        bool $savePaymentMethod = false,
        bool $offSession = false,
        ?BillingAddress $billingAddress = null,
        bool $delegated = false,
        ?Split $split = null,
        ?PaymentActionKind $hosted = null,
        ?string $returnUrl = null,
    ): self {
        $currency ??= $lineItems[0]->amount->currency ?? config('commerce.currency', 'USD');

        $total = Money::zero($currency);
        foreach ($lineItems as $line) {
            $total = $total->plus($line->amount);
        }

        return new self(
            id: (string) Str::uuid(),
            customer: $customer,
            currency: $currency,
            lineItems: $lineItems,
            cadence: $cadence,
            total: $total,
            kind: $kind ?? self::defaultKindFor($cadence),
            reference: $reference,
            paymentMethodRef: $paymentMethodRef,
            savePaymentMethod: $savePaymentMethod,
            offSession: $offSession,
            billingAddress: $billingAddress,
            delegated: $delegated,
            split: $split,
            hosted: $hosted,
            returnUrl: $returnUrl,
        );
    }

    private static function defaultKindFor(Cadence $cadence): PurchaseKind
    {
        return $cadence === Cadence::Recurring
            ? PurchaseKind::Subscription
            : PurchaseKind::Perpetual;
    }

    public static function schemaName(): string
    {
        return 'commerce/order';
    }

    /**
     * Version 2 (purchase-walkthrough BUY-02): gained `hosted` and `returnUrl`, additive and nullable, so the v1 -> v2 step is the
     * identity on a stored payload. v1 stays frozen as written.
     */
    public static function schemaVersion(): int
    {
        return 2;
    }
}
