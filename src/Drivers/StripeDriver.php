<?php

namespace Rushing\Commerce\Drivers;

use Illuminate\Support\Str;
use RuntimeException;
use Rushing\Commerce\Contracts\CustomerVault;
use Rushing\Commerce\Contracts\MerchantResolver;
use Rushing\Commerce\Contracts\MoneyInDriver;
use Rushing\Commerce\Contracts\StripeClientFactory;
use Rushing\Commerce\Contracts\SubscriptionBinder;
use Rushing\Commerce\Data\BillingAddress;
use Rushing\Commerce\Data\Merchant;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Data\Payment;
use Rushing\Commerce\Data\Refund;
use Rushing\Commerce\Enums\Cadence;
use Rushing\Commerce\Enums\PaymentStatus;
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

        $intent = $this->clients->for($merchant)->paymentIntents->create(
            $this->intentParams($order, $merchant)
        );

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

        // Referencing or saving a card needs a provider customer to attach it to.
        if ($order->paymentMethodRef !== null || $order->savePaymentMethod) {
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
