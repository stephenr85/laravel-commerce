<?php

namespace Rushing\Commerce\Support;

use Illuminate\Support\Str;
use Rushing\Commerce\Contracts\CustomerVault;
use Rushing\Commerce\Data\Customer;
use Rushing\Commerce\Data\Merchant;
use Rushing\Commerce\Data\SetupTicket;
use Rushing\Commerce\Data\VaultedCustomer;
use Rushing\Commerce\Events\PaymentMethodsChanged;

/**
 * The card vault of the `fake` rail (purchase-walkthrough BUY-02 part B4, rule BUY-3): card saving reaches its effect
 * with nothing sent anywhere. It keeps no cards, but it announces changes as the real vault does, so listeners behave
 * the same on both rails.
 */
class FakeCustomerVault implements CustomerVault
{
    public function resolveCustomer(Customer $customer, Merchant $merchant): VaultedCustomer
    {
        return new VaultedCustomer(customerId: $customer->id, providerRef: 'fake_cus_'.$customer->id, merchantId: $merchant->id);
    }

    public function beginSetup(Customer $customer, Merchant $merchant): SetupTicket
    {
        $id = 'fake_seti_'.Str::lower(Str::random(16));

        return new SetupTicket(clientSecret: $id.'_secret', providerRef: $id, merchantId: $merchant->id);
    }

    public function paymentMethods(Customer $customer, Merchant $merchant): array
    {
        return [];
    }

    public function setDefaultPaymentMethod(Customer $customer, Merchant $merchant, string $paymentMethodRef): void
    {
        PaymentMethodsChanged::dispatch($customer);
    }

    public function forgetPaymentMethod(Customer $customer, Merchant $merchant, string $paymentMethodRef): void
    {
        PaymentMethodsChanged::dispatch($customer);
    }
}
