<?php

namespace Rushing\Commerce\Drivers;

use Illuminate\Support\Str;
use RuntimeException;
use Rushing\Commerce\Contracts\CustomerVault;
use Rushing\Commerce\Contracts\MerchantResolver;
use Rushing\Commerce\Contracts\MoneyInDriver;
use Rushing\Commerce\Contracts\SubscriptionBinder;
use Rushing\Commerce\Data\BillingAddress;
use Rushing\Commerce\Data\Merchant;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Data\Payment;
use Rushing\Commerce\Data\Refund;
use Rushing\Commerce\Enums\Cadence;
use Rushing\Commerce\Enums\PaymentStatus;
use Rushing\Commerce\Stripe\StripeClientFactory;
use Stripe\Exception\CardException;
use Stripe\PaymentIntent;

/**
 * Collects real money for an Order on the billing party's own Stripe account.
 * One-off Orders go through PaymentIntents directly; recurring Orders delegate to
 * a host SubscriptionBinder (Cashier). We call the Stripe SDK directly and only
 * normalize the outcome into our neutral DTOs (ADR-0001) — the same records and
 * events the fake driver produces, so a feature test can drive either mode.
 */
class StripeDriver implements MoneyInDriver
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

    public function pay(Order $order): Payment
    {
        $merchant = $this->merchants->forOrder($order);

        if ($order->cadence === Cadence::Recurring) {
            return $this->subscribe($order, $merchant);
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

        return $params;
    }

    /**
     * Per-request Stripe options. An off-session reload seeds a deterministic
     * Idempotency-Key from the Order reference (the reload reason) so a network
     * retry of the *same* window can never double-charge. Interactive charges keep
     * Stripe's default (no key); the durable guard remains topUpOnce(reason).
     *
     * @return array<string, mixed>
     */
    private function intentOptions(Order $order): array
    {
        if ($order->offSession && $order->reference !== null) {
            return ['idempotency_key' => $order->reference];
        }

        return [];
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
