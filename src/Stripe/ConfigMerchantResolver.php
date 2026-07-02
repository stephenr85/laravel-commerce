<?php

declare(strict_types=1);

namespace Rushing\Commerce\Stripe;

use Rushing\Commerce\Contracts\MerchantResolver;
use Rushing\Commerce\Data\Merchant;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Data\Payment;

/**
 * The default single-Merchant resolver for a non-tenant consumer: every Order and
 * Payment collects on the one account configured for the app. A tenancy-aware host
 * (the satellite layer) replaces this with a resolver that returns the billing
 * party `parent_tenant_id ?? self`.
 */
final class ConfigMerchantResolver implements MerchantResolver
{
    public function forOrder(Order $order): Merchant
    {
        return $this->default();
    }

    public function forPayment(Payment $payment): Merchant
    {
        return $payment->merchantId !== null
            ? new Merchant(id: $payment->merchantId)
            : $this->default();
    }

    private function default(): Merchant
    {
        return new Merchant(
            id: (string) config('commerce.stripe.merchant_id', 'default'),
            name: config('commerce.stripe.merchant_name'),
        );
    }
}
