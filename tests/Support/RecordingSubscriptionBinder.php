<?php

namespace Rushing\Commerce\Tests\Support;

use Illuminate\Support\Str;
use Rushing\Commerce\Contracts\SubscriptionBinder;
use Rushing\Commerce\Data\Merchant;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Data\Payment;
use Rushing\Commerce\Enums\PaymentStatus;

/**
 * Stands in for a host's Cashier-backed binder: records the call and returns a
 * succeeded term Payment, so the driver's recurring route is exercised without a
 * Billable model or a live subscription.
 */
class RecordingSubscriptionBinder implements SubscriptionBinder
{
    public ?Order $boundOrder = null;

    public ?Merchant $boundMerchant = null;

    public function bind(Order $order, Merchant $merchant): Payment
    {
        $this->boundOrder = $order;
        $this->boundMerchant = $merchant;

        return new Payment(
            id: (string) Str::uuid(),
            orderId: $order->id,
            amount: $order->total,
            status: PaymentStatus::Succeeded,
            driver: 'stripe',
            providerRef: 'sub_fake_123',
            merchantId: $merchant->id,
        );
    }
}
