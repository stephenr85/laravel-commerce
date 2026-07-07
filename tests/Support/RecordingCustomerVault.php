<?php

namespace Rushing\Commerce\Tests\Support;

use Rushing\Commerce\Contracts\CustomerVault;
use Rushing\Commerce\Data\Customer;
use Rushing\Commerce\Data\Merchant;
use Rushing\Commerce\Data\SetupTicket;
use Rushing\Commerce\Data\VaultedCustomer;
use Rushing\Commerce\Data\VaultedPaymentMethod;

/**
 * A test double standing in for a host's Cashier-backed vault: it hands back canned
 * references and records what it was asked to do, so the driver's use of the seam can
 * be asserted with no provider and no live keys.
 */
class RecordingCustomerVault implements CustomerVault
{
    public ?Customer $resolvedCustomer = null;

    public ?Merchant $resolvedMerchant = null;

    public function resolveCustomer(Customer $customer, Merchant $merchant): VaultedCustomer
    {
        $this->resolvedCustomer = $customer;
        $this->resolvedMerchant = $merchant;

        return new VaultedCustomer(
            customerId: $customer->id,
            providerRef: 'cus_vault_'.$customer->id,
            merchantId: $merchant->id,
        );
    }

    public function beginSetup(Customer $customer, Merchant $merchant): SetupTicket
    {
        return new SetupTicket(
            clientSecret: 'seti_vault_secret',
            providerRef: 'seti_vault_123',
            merchantId: $merchant->id,
        );
    }

    public function paymentMethods(Customer $customer, Merchant $merchant): array
    {
        return [
            new VaultedPaymentMethod(
                ref: 'pm_vault_default',
                brand: 'visa',
                last4: '4242',
                expMonth: 12,
                expYear: 2030,
                isDefault: true,
            ),
        ];
    }

    public function setDefaultPaymentMethod(Customer $customer, Merchant $merchant, string $paymentMethodRef): void {}

    public function forgetPaymentMethod(Customer $customer, Merchant $merchant, string $paymentMethodRef): void {}
}
