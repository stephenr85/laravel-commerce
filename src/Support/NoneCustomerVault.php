<?php

namespace Rushing\Commerce\Support;

use Rushing\Commerce\Contracts\CustomerVault;
use Rushing\Commerce\Data\Customer;
use Rushing\Commerce\Data\Merchant;
use Rushing\Commerce\Data\SetupTicket;
use Rushing\Commerce\Data\VaultedCustomer;
use Rushing\Commerce\Exceptions\MoneyInUnavailable;

/**
 * The card vault of the `none` rail (BUY-4, C-3): a host that collects no money saves and charges no card. Writes refuse
 * loudly; listing answers no cards, so a read surface degrades rather than errors.
 */
class NoneCustomerVault implements CustomerVault
{
    public function resolveCustomer(Customer $customer, Merchant $merchant): VaultedCustomer
    {
        throw MoneyInUnavailable::make('resolve a payment customer');
    }

    public function beginSetup(Customer $customer, Merchant $merchant): SetupTicket
    {
        throw MoneyInUnavailable::make('save a card');
    }

    public function paymentMethods(Customer $customer, Merchant $merchant): array
    {
        return [];
    }

    public function setDefaultPaymentMethod(Customer $customer, Merchant $merchant, string $paymentMethodRef): void
    {
        throw MoneyInUnavailable::make('change a saved card');
    }

    public function forgetPaymentMethod(Customer $customer, Merchant $merchant, string $paymentMethodRef): void
    {
        throw MoneyInUnavailable::make('remove a saved card');
    }
}
