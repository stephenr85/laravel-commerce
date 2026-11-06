<?php

namespace Rushing\Commerce\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Rushing\Commerce\Contracts\CustomerVault;
use Rushing\Commerce\Contracts\MerchantResolver;
use Rushing\Commerce\Contracts\MoneyInDriver;
use Rushing\Commerce\Contracts\ReversesDisputedTransfers;
use Rushing\Commerce\Contracts\SubscriptionBinder;
use Rushing\Commerce\Contracts\SyncsPlanCatalog;
use Rushing\Commerce\Data\BillingAddress;
use Rushing\Commerce\Data\CatalogPlan;
use Rushing\Commerce\Data\Customer;
use Rushing\Commerce\Data\Dispute;
use Rushing\Commerce\Data\LineItem;
use Rushing\Commerce\Data\Merchant;
use Rushing\Commerce\Data\Money;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Data\Payment;
use Rushing\Commerce\Data\PaymentAction;
use Rushing\Commerce\Data\PlanCatalogRefs;
use Rushing\Commerce\Data\Refund;
use Rushing\Commerce\Data\Split;
use Rushing\Commerce\Enums\Cadence;
use Rushing\Commerce\Enums\PaymentActionKind;
use Rushing\Commerce\Enums\PaymentStatus;
use Rushing\Commerce\Enums\PurchaseKind;
use Rushing\Commerce\Stripe\StripeClientFactory;
use Rushing\Commerce\Stripe\StripePlanCatalog;
use Stripe\Exception\CardException;
use Stripe\PaymentIntent;

/**
 * Collects real money for an Order on the billing party's own Stripe account.
 * One-off Orders go through PaymentIntents directly; recurring Orders delegate to
 * a host SubscriptionBinder (Cashier). We call the Stripe SDK directly and only
 * normalize the outcome into our neutral DTOs (ADR-0001) — the same records and
 * events the fake driver produces, so a feature test can drive either mode.
 *
 * Ticket 06 (splicewire-marketplace build, see `docs/adr/0196-*.md`) adds a SECOND, entirely
 * separate transport for the one case an Order/Payment carries a {@see Split}:
 * `payWithSplit()`/`refundWithSplit()`/`reverseTransferForDispute()` talk to Stripe's `/v1/*` REST
 * endpoints over Laravel's own `Http` client rather than the `stripe-php` SDK — the same choice the
 * marketplace's own payout-gateway resolver (ticket 05) made for its own provisioning calls, for the
 * same reason: it lets a test assert the exact split-payment params via `Http::fake()`. The existing
 * SDK-based `pay()`/`refund()` path for a split-less Order/Payment is completely untouched — zero
 * behavior change for every non-marketplace caller of this driver.
 *
 * owner-review-20261102 O2: it also keeps the merchant's Stripe catalog in step with a plan's fee
 * ({@see SyncsPlanCatalog}, implemented by {@see StripePlanCatalog}). The fee stays the price of record; nothing on
 * the charge path reads the catalog.
 */
class StripeDriver implements MoneyInDriver, ReversesDisputedTransfers, SyncsPlanCatalog
{
    public function __construct(
        private StripeClientFactory $clients,
        private MerchantResolver $merchants,
        private ?SubscriptionBinder $subscriptions = null,
        private ?CustomerVault $vault = null,
    ) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function syncPlan(CatalogPlan $plan, Merchant $merchant, ?PlanCatalogRefs $recorded = null): PlanCatalogRefs
    {
        return (new StripePlanCatalog($this->clients->for($merchant), $merchant->id))->sync($plan, $recorded);
    }

    public function retirePlan(PlanCatalogRefs $recorded, Merchant $merchant): PlanCatalogRefs
    {
        return (new StripePlanCatalog($this->clients->for($merchant), $merchant->id))->retire($recorded);
    }

    public function pay(Order $order): Payment
    {
        $merchant = $this->merchants->forOrder($order);

        // BUY-02 (C-11): a hosted leg is a Checkout Session the customer completes; the Payment comes back
        // RequiresAction carrying one `action` and settles through checkout.session.completed.
        if ($order->hosted !== null) {
            if (! $order->kind->settlesHosted()) {
                throw new InvalidArgumentException("A hosted leg for a [{$order->kind->value}] purchase has no settling translator yet; it would charge and grant nothing (BUY-02).");
            }
            if ($order->cadence === Cadence::Recurring) {
                throw new RuntimeException('A hosted leg is for one-time Orders; subscriptions go through the SubscriptionBinder (purchase-walkthrough BUY-04).');
            }

            return $this->hostedCheckout($order, $merchant);
        }

        if ($order->cadence === Cadence::Recurring) {
            return $this->subscribe($order, $merchant);
        }

        // Ticket 06: an Order carrying a Split is ALWAYS the marketplace checkout's one shared
        // entry point (a first-party $0-fee sale included — see Split's own docblock on a null
        // payeeRef) — never the legacy SDK path below, which stays byte-for-byte what it was
        // before this ticket for every non-marketplace Order.
        if ($order->split !== null) {
            return $this->payWithSplit($order, $merchant);
        }

        // An off-session confirm can hard-decline (card_declined family) or demand a
        // step-up (authentication_required, surfaced by error_on_requires_action): Stripe
        // raises a CardException. Normalize it into a neutral non-success Payment carrying
        // the code so a host classifies the failure without a Stripe type. Transient
        // (network / rate_limit / 5xx) exceptions are NOT caught — they propagate so an
        // orchestrating queue can retry the same idempotency key safely.
        try {
            $intent = $this->clients->for($merchant)->paymentIntents->create(
                $this->intentParams($order, $merchant),
                $this->intentOptions($order),
            );
        } catch (CardException $e) {
            return $this->failedPayment($order, $merchant, $e);
        }

        return new Payment(
            id: (string) Str::uuid(),
            orderId: $order->id,
            amount: $order->total,
            status: self::statusFromIntent($intent->status),
            driver: $this->name(),
            providerRef: $intent->id,
            merchantId: $merchant->id,
        );
    }

    /**
     * Normalize a declined/step-up off-session charge into a neutral Payment. An
     * authentication_required outcome is RequiresAction (a live step-up could still
     * clear it); every other card error is a hard Failed. The code (decline_code
     * when Stripe gives one, else the error code) rides for host classification, and
     * the PaymentIntent id — if the error carries one — for the audit trail.
     */
    private function failedPayment(Order $order, Merchant $merchant, CardException $e): Payment
    {
        $error = $e->getError();
        $code = $error->decline_code ?? $error->code ?? 'card_error';

        $status = ($error->code ?? null) === 'authentication_required'
            ? PaymentStatus::RequiresAction
            : PaymentStatus::Failed;

        $intent = $error->payment_intent ?? null;

        return new Payment(
            id: (string) Str::uuid(),
            orderId: $order->id,
            amount: $order->total,
            status: $status,
            driver: $this->name(),
            providerRef: is_object($intent) ? ($intent->id ?? null) : null,
            merchantId: $merchant->id,
            errorCode: $code,
        );
    }

    /**
     * The customer's Stripe billing portal (BUY-02: the portal goes through the rail, never a Cashier call). The
     * merchant resolves from a line-less Order for the customer: both shipped resolvers answer by party, not by lines.
     */
    public function manage(Customer $customer, string $returnUrl): PaymentAction
    {
        $merchant = $this->merchants->forOrder(Order::for($customer, []));

        $session = $this->clients->for($merchant)->billingPortal->sessions->create([
            'customer' => $this->vaultedCustomer($customer, $merchant, 'A billing portal'),
            'return_url' => $returnUrl,
        ]);

        return PaymentAction::redirect((string) $session->url);
    }

    /**
     * A Checkout Session for a one-time Order: `embedded` returns its client secret, `redirect` its URL. The metadata is
     * exactly what {@see \Rushing\Commerce\Stripe\StripeWebhookTranslator::creditTopup()} reads back: `kind` selects the
     * path and `party_ref` is the beneficiary. A subscription's session is a card setup instead (mode=setup), read back by
     * {@see \Rushing\Commerce\Stripe\StripeWebhookTranslator::subscriptionSetup()}.
     */
    private function hostedCheckout(Order $order, Merchant $merchant): Payment
    {
        $return = $order->returnUrl ?? throw new RuntimeException('A hosted leg needs Order::$returnUrl to send the customer back to.');
        $embedded = $order->hosted === PaymentActionKind::Embedded;
        $metadata = array_filter([
            'kind' => $order->kind->value,
            'party_ref' => $order->customer->id,
            'order_id' => $order->id,
            'order_reference' => $order->reference,
        ], fn ($value) => $value !== null);

        // BUY-04 (BQ-2): a subscription's hosted leg saves a card and charges NOTHING (no line items, no price): the
        // host's own bills are the price of record. The metadata rides on the SetupIntent, where
        // StripeWebhookTranslator::subscriptionSetup() reads it back from setup_intent.succeeded with the card.
        $params = $order->kind === PurchaseKind::Subscription ? [
            'mode' => 'setup',
            'currency' => Str::lower($order->currency),
            'client_reference_id' => $order->customer->id,
            'metadata' => $metadata,
            'setup_intent_data' => ['metadata' => $metadata],
        ] : [
            'mode' => 'payment',
            'line_items' => array_map(fn (LineItem $line): array => [
                'quantity' => $line->quantity,
                'price_data' => [
                    'currency' => Str::lower($line->unitPrice->currency),
                    'unit_amount' => $line->unitPrice->minorUnits,
                    'product_data' => ['name' => $line->description],
                ],
            ], $order->lineItems),
            'client_reference_id' => $order->customer->id,
            'metadata' => $metadata,
        ];
        if ($this->vault !== null) {
            $params['customer'] = $this->vault->resolveCustomer($order->customer, $merchant)->providerRef;
        }
        if ($embedded) {
            $params['ui_mode'] = 'embedded';
            $params['return_url'] = $return;
        } else {
            $params['success_url'] = $return;
            $params['cancel_url'] = $return;
        }

        $session = $this->clients->for($merchant)->checkout->sessions->create($params, ['idempotency_key' => self::idempotencyKey($order)]);

        return new Payment(
            id: (string) Str::uuid(),
            orderId: $order->id,
            amount: $order->total,
            status: PaymentStatus::RequiresAction,
            driver: $this->name(),
            providerRef: $session->id,
            merchantId: $merchant->id,
            action: $embedded
                ? PaymentAction::embedded((string) $session->client_secret)
                : PaymentAction::redirect((string) $session->url),
        );
    }

    private function vaultedCustomer(Customer $customer, Merchant $merchant, string $what): string
    {
        if ($this->vault === null) {
            throw new RuntimeException("{$what} needs a CustomerVault implementation. Bind ".CustomerVault::class.' in the host.');
        }

        return $this->vault->resolveCustomer($customer, $merchant)->providerRef;
    }

    public function refund(Payment $payment): Refund
    {
        $merchant = $this->merchants->forPayment($payment);

        $refund = $this->clients->for($merchant)->refunds->create(array_filter([
            'payment_intent' => $payment->providerRef,
        ], fn ($value) => $value !== null));

        return new Refund(
            id: (string) Str::uuid(),
            paymentId: $payment->id,
            amount: $payment->amount,
            driver: $this->name(),
            providerRef: $refund->id,
        );
    }

    /**
     * Ticket 06's split-payment charge path — the marketplace checkout's ONE shared entry point,
     * for a paid third-party listing (a real `payeeRef`) and a first-party $0-fee listing (a null
     * `payeeRef`) alike; see `pay()`'s own dispatch and `Split`'s docblock. Builds the exact same
     * `intentParams()` the legacy SDK path builds (vault/billing-address handling included), then
     * posts them to Stripe's `/v1/payment_intents` over `Http` instead of the SDK — see this
     * class's own docblock for why.
     */
    private function payWithSplit(Order $order, Merchant $merchant): Payment
    {
        $response = $this->stripeHttp($merchant)
            ->withHeaders(['Idempotency-Key' => self::idempotencyKey($order)])
            ->post('https://api.stripe.com/v1/payment_intents', $this->intentParams($order, $merchant));

        if ($response->failed()) {
            return $this->failedSplitPayment($order, $merchant, $response);
        }

        return new Payment(
            id: (string) Str::uuid(),
            orderId: $order->id,
            amount: $order->total,
            status: self::statusFromIntent((string) $response->json('status')),
            driver: $this->name(),
            providerRef: (string) $response->json('id'),
            merchantId: $merchant->id,
            split: $order->split,
        );
    }

    /**
     * The `Http`-path mirror of {@see failedPayment()} — normalizes a Stripe error *response body*
     * (rather than an SDK `CardException`) into a neutral, non-success Payment.
     */
    private function failedSplitPayment(Order $order, Merchant $merchant, Response $response): Payment
    {
        $error = (array) ($response->json('error') ?? []);
        $code = $error['decline_code'] ?? $error['code'] ?? 'card_error';

        $status = ($error['code'] ?? null) === 'authentication_required'
            ? PaymentStatus::RequiresAction
            : PaymentStatus::Failed;

        return new Payment(
            id: (string) Str::uuid(),
            orderId: $order->id,
            amount: $order->total,
            status: $status,
            driver: $this->name(),
            providerRef: $error['payment_intent']['id'] ?? null,
            merchantId: $merchant->id,
            errorCode: $code,
            split: $order->split,
        );
    }

    /**
     * Ticket 06 — refund a split-carrying Payment, pro-rating BOTH sides of the split rather than
     * letting either absorb a partial refund entirely: `refund_application_fee` lets Stripe refund
     * the platform's application fee proportionally to the refunded amount (Stripe's own documented
     * destination-charge refund behavior), and `reverse_transfer` + an EXPLICITLY computed
     * `transfer_reversal_amount` reclaims the matching proportion of the Seller's transfer — the
     * explicit number (not just the boolean flags) is this method's own arithmetic, asserted
     * directly by the feature test rather than trusted to happen invisibly on Stripe's side.
     *
     * A Payment with no Split (or a Split with no `payeeRef` — a first-party sale) still goes
     * through this same method when the caller knows it dealt in Splits at charge time; it simply
     * never adds the split-specific params, so it degrades to a plain refund.
     *
     * Not part of {@see MoneyInDriver} — deliberately a StripeDriver-only extension a marketplace
     * caller reaches by type-hinting this concrete driver, keeping the neutral `refund()` contract
     * (and every other MoneyInDriver implementer, e.g. FakeDriver) completely untouched.
     */
    public function refundWithSplit(Payment $payment, ?Money $amount = null): Refund
    {
        $merchant = $this->merchants->forPayment($payment);
        $amount ??= $payment->amount;

        $params = array_filter([
            'payment_intent' => $payment->providerRef,
            'amount' => $amount->minorUnits,
        ], fn ($value) => $value !== null);

        if ($payment->split !== null && $payment->split->payeeRef !== null) {
            $transferPortion = $payment->amount->minorUnits - $payment->split->platformFee->minorUnits;

            $params['refund_application_fee'] = 'true';
            $params['reverse_transfer'] = 'true';
            $params['transfer_reversal_amount'] = self::proratedMinorUnits(
                $transferPortion,
                $amount->minorUnits,
                $payment->amount->minorUnits,
            );
        }

        $response = $this->stripeHttp($merchant)->post('https://api.stripe.com/v1/refunds', $params)->throw();

        return new Refund(
            id: (string) Str::uuid(),
            paymentId: $payment->id,
            amount: $amount,
            driver: $this->name(),
            providerRef: (string) $response->json('id'),
        );
    }

    /**
     * Ticket 06's `charge.dispute.created` reaction: reverses the Stripe transfer for the disputed
     * charge, pro-rated to the disputed amount — per the marketplace's own payout-gateway
     * provisioning (ticket 05), Splicewire ("the application") is liable for fees/losses on every
     * Seller's payee account, so a dispute already debits the PLATFORM's own Stripe balance; this
     * reclaims the matching proportion from the Seller's side rather than leaving the platform to
     * absorb it alone. Looks the live charge up (`GET /v1/charges/{id}`) rather than trusting
     * anything cached, since the dispute payload itself carries no transfer id — a charge with no
     * `transfer` (never a split charge to begin with) is a no-op.
     */
    public function reverseTransferForDispute(Dispute $dispute, ?Merchant $merchant = null): void
    {
        $merchant ??= $this->merchants->forPayment(new Payment(
            id: (string) Str::uuid(),
            orderId: '',
            amount: Money::zero($dispute->amount->currency),
            status: PaymentStatus::Succeeded,
            driver: $this->name(),
        ));

        $charge = $this->stripeHttp($merchant)->get(
            "https://api.stripe.com/v1/charges/{$dispute->chargeRef}",
        )->throw();

        $transferId = $charge->json('transfer');

        if ($transferId === null) {
            return;
        }

        $chargeAmount = (int) $charge->json('amount');
        $applicationFee = (int) ($charge->json('application_fee_amount') ?? 0);
        $transferPortion = $chargeAmount - $applicationFee;

        $reversalAmount = self::proratedMinorUnits($transferPortion, $dispute->amount->minorUnits, $chargeAmount);

        $this->stripeHttp($merchant)->post(
            "https://api.stripe.com/v1/transfers/{$transferId}/reversals",
            ['amount' => $reversalAmount],
        )->throw();
    }

    /**
     * The one place this driver's split-payment arithmetic lives: `$whole` prorated to the same
     * fraction `$portion` is of `$total`, floored to the nearest minor unit (never over-refund/
     * over-reverse). `$total` of 0 (a $0 Order — never reachable in practice, since a Split's own
     * platformFee is 0 in that case too) short-circuits to 0 rather than dividing by zero.
     */
    private static function proratedMinorUnits(int $whole, int $portion, int $total): int
    {
        if ($total === 0 || $whole === 0) {
            return 0;
        }

        return intdiv($whole * $portion, $total);
    }

    /**
     * A pre-configured `Http` pending request bound to the Merchant's own Stripe secret — pulled
     * from the SAME {@see StripeClientFactory} seam the SDK path uses (`getApiKey()` is the
     * `stripe-php` client's own accessor for the secret it was constructed with), so a tenancy-
     * aware host's `StripeClientFactory` rebinding covers this transport too, with no separate
     * config to keep in sync.
     */
    private function stripeHttp(Merchant $merchant): PendingRequest
    {
        return Http::asForm()->withToken((string) $this->clients->for($merchant)->getApiKey());
    }

    /**
     * The PaymentIntent params for a one-off Order. A bare Order charges a fresh card
     * confirmed on the client; an Order that references a saved card, opts to save the
     * card, or carries a billing address layers the vault fields on top.
     *
     * @return array<string, mixed>
     */
    private function intentParams(Order $order, Merchant $merchant): array
    {
        $params = [
            'amount' => $order->total->minorUnits,
            'currency' => Str::lower($order->total->currency),
            'metadata' => array_filter([
                'order_id' => $order->id,
                'order_reference' => $order->reference,
                'customer_id' => $order->customer->id,
            ], fn ($value) => $value !== null),
        ];

        // Referencing or saving a *stored* card needs a provider customer to attach it to.
        // A delegated/shared token (ACP sell-side) is the exception: it charges directly on
        // the merchant's own account as merchant-of-record with no customer of ours (the
        // buyer's agent vaulted it upstream), so we never resolve a CustomerVault for it —
        // and nothing Stripe-identity-shaped is persisted below the seam (ADR-0131).
        if (! $order->delegated && ($order->paymentMethodRef !== null || $order->savePaymentMethod)) {
            if ($this->vault === null) {
                throw new RuntimeException(
                    'Saving or charging a stored card requires a CustomerVault implementation. Bind '
                    .CustomerVault::class.' in the host (e.g. backed by Laravel Cashier) to use saved '
                    .'payment methods.'
                );
            }

            $params['customer'] = $this->vault->resolveCustomer($order->customer, $merchant)->providerRef;
        }

        // Charge an already-saved card server-side; off-session when the Customer isn't present.
        if ($order->paymentMethodRef !== null) {
            $params['payment_method'] = $order->paymentMethodRef;
            $params['confirm'] = true;

            if ($order->offSession) {
                $params['off_session'] = true;
                // No user is present to answer a step-up, so turn a requires_action into a
                // hard decline (CardException) the host can classify, not a limbo intent.
                $params['error_on_requires_action'] = true;
            }
        }

        // Remember the card presented at checkout for future off-session charges.
        if ($order->savePaymentMethod) {
            $params['setup_future_usage'] = 'off_session';
        }

        // A billing address rides a server-initiated fresh-card charge; when charging a
        // saved card the details already live on that payment method, and a client-confirmed
        // Elements flow attaches them there too — so only send them on the fresh path.
        if ($order->billingAddress !== null && $order->paymentMethodRef === null) {
            $params['payment_method_data'] = [
                'billing_details' => self::billingDetails($order->billingAddress),
            ];
        }

        // Ticket 06: the destination-charge params, ONLY when the Split actually names a payee —
        // a first-party ($0-fee) Split (null payeeRef) intentionally adds neither key, so that
        // charge is a plain PaymentIntent with the fee forced to zero by omission, not by a
        // separate code path (see `pay()`'s own dispatch + Split's docblock).
        if ($order->split !== null && $order->split->payeeRef !== null) {
            $params['application_fee_amount'] = $order->split->platformFee->minorUnits;
            $params['transfer_data'] = ['destination' => $order->split->payeeRef];
        }

        return $params;
    }

    /**
     * Per-request Stripe options: every charge carries an Idempotency-Key ({@see idempotencyKey()}).
     *
     * @return array<string, mixed>
     */
    private function intentOptions(Order $order): array
    {
        return ['idempotency_key' => self::idempotencyKey($order)];
    }

    /**
     * The Idempotency-Key a charge for this Order sends, so a retry of the same Order — a network
     * retry, or a host replaying an attempt it persisted before calling — can never charge twice.
     * An off-session reload keys on its Order reference (the reload reason), so a retry of the
     * *same* window dedupes across Orders; the durable guard there remains topUpOnce(reason). Any
     * other charge keys on its Order id: one Order, one charge. A host that wants a retry deduped
     * reuses the Order (its id), not a fresh one.
     */
    private static function idempotencyKey(Order $order): string
    {
        if ($order->offSession && $order->reference !== null) {
            return $order->reference;
        }

        return "order:{$order->id}";
    }

    /**
     * @return array<string, mixed>
     */
    private static function billingDetails(BillingAddress $address): array
    {
        return array_filter([
            'name' => $address->name,
            'address' => array_filter([
                'line1' => $address->line1,
                'line2' => $address->line2,
                'city' => $address->city,
                'state' => $address->state,
                'postal_code' => $address->postalCode,
                'country' => $address->country,
            ], fn ($value) => $value !== null),
        ], fn ($value) => $value !== null && $value !== []);
    }

    private function subscribe(Order $order, Merchant $merchant): Payment
    {
        if ($this->subscriptions === null) {
            throw new RuntimeException(
                'Recurring Stripe money-in requires a SubscriptionBinder implementation (bind one with '
                .'Laravel Cashier). Bind '.SubscriptionBinder::class.' in the host to charge recurring Orders.'
            );
        }

        return $this->subscriptions->bind($order, $merchant);
    }

    private static function statusFromIntent(string $status): PaymentStatus
    {
        return match ($status) {
            PaymentIntent::STATUS_SUCCEEDED => PaymentStatus::Succeeded,
            PaymentIntent::STATUS_CANCELED => PaymentStatus::Failed,
            PaymentIntent::STATUS_REQUIRES_ACTION,
            PaymentIntent::STATUS_REQUIRES_CONFIRMATION => PaymentStatus::RequiresAction,
            default => PaymentStatus::Pending,
        };
    }
}
