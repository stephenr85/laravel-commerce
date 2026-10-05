<?php

namespace Rushing\Commerce\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Rushing\Commerce\Contracts\CustomerVault;
use Rushing\Commerce\Data\Customer;
use Rushing\Commerce\Data\Merchant;
use Rushing\Commerce\Data\SetupTicket;
use Rushing\Commerce\Data\VaultedCustomer;
use Rushing\Commerce\Data\VaultedPaymentMethod;
use Rushing\Commerce\Events\PaymentMethodsChanged;

/**
 * The card vault of the `fake` rail (purchase-walkthrough BUY-02 part B4, rule BUY-3): card saving reaches its effect
 * with nothing sent anywhere. It keeps no cards, but it announces changes as the real vault does, so listeners behave
 * the same on both rails.
 */
class FakeCustomerVault implements CustomerVault
{
    /**
     * Cache key prefix for the default card this vault remembers per customer, so the fake rail can charge a saved card
     * off-session as the stripe vault does (BUY-04 part B). The untagged store, as the fake rail's pending legs use.
     */
    public const DEFAULT = 'commerce.fake.default_card.';

    public function resolveCustomer(Customer $customer, Merchant $merchant): VaultedCustomer
    {
        return new VaultedCustomer(customerId: $customer->id, providerRef: 'fake_cus_'.$customer->id, merchantId: $merchant->id);
    }

    public function beginSetup(Customer $customer, Merchant $merchant): SetupTicket
    {
        $id = 'fake_seti_'.Str::lower(Str::random(16));

        return new SetupTicket(clientSecret: $id.'_secret', providerRef: $id, merchantId: $merchant->id);
    }

    /** The one default card this offline vault remembers for the customer (BUY-04 part B), if any. */
    public function paymentMethods(Customer $customer, Merchant $merchant): array
    {
        $ref = Cache::store()->get(self::DEFAULT.$customer->id);

        return is_string($ref)
            ? [new VaultedPaymentMethod(ref: $ref, brand: 'visa', last4: '4242', expMonth: 12, expYear: (int) date('Y') + 3, isDefault: true)]
            : [];
    }

    public function setDefaultPaymentMethod(Customer $customer, Merchant $merchant, string $paymentMethodRef): void
    {
        Cache::store()->forever(self::DEFAULT.$customer->id, $paymentMethodRef);
        PaymentMethodsChanged::dispatch($customer);
    }

    public function forgetPaymentMethod(Customer $customer, Merchant $merchant, string $paymentMethodRef): void
    {
        if (Cache::store()->get(self::DEFAULT.$customer->id) === $paymentMethodRef) {
            Cache::store()->forget(self::DEFAULT.$customer->id);
        }
        PaymentMethodsChanged::dispatch($customer);
    }
}
