<?php

declare(strict_types=1);

namespace Rushing\Commerce\Drivers;

use Illuminate\Support\Str;
use RuntimeException;
use Rushing\Commerce\Contracts\MerchantResolver;
use Rushing\Commerce\Contracts\MoneyInDriver;
use Rushing\Commerce\Contracts\StripeClientFactory;
use Rushing\Commerce\Contracts\SubscriptionBinder;
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
final class StripeDriver implements MoneyInDriver
{
    public function __construct(
        private StripeClientFactory $clients,
        private MerchantResolver $merchants,
        private ?SubscriptionBinder $subscriptions = null,
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

        $intent = $this->clients->for($merchant)->paymentIntents->create([
            'amount' => $order->total->minorUnits,
            'currency' => Str::lower($order->total->currency),
            'metadata' => array_filter([
                'order_id' => $order->id,
                'order_reference' => $order->reference,
                'customer_id' => $order->customer->id,
            ], fn ($value) => $value !== null),
        ]);

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
            default => PaymentStatus::Pending,
        };
    }
}
