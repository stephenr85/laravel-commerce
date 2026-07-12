<?php

namespace Rushing\Commerce\Data;

use Illuminate\Support\Str;
use Rushing\Commerce\Enums\Cadence;
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

    public static function schemaVersion(): int
    {
        return 1;
    }
}
