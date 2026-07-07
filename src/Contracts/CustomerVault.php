<?php

namespace Rushing\Commerce\Contracts;

use Rushing\Commerce\Data\Customer;
use Rushing\Commerce\Data\Merchant;
use Rushing\Commerce\Data\SetupTicket;
use Rushing\Commerce\Data\VaultedCustomer;
use Rushing\Commerce\Data\VaultedPaymentMethod;

/**
 * The card-on-file seam. Optional and host-implemented — the engine defines it and
 * binds no default (like SubscriptionBinder), so an app that never saves a card
 * needs no vault. Every method is scoped by (Customer, Merchant): a saved card
 * belongs to one provider account, so which Merchant collects decides which vault
 * a card lives in. A host typically backs this with Laravel Cashier's Billable.
 */
interface CustomerVault
{
    /**
     * Create-or-get the provider customer for this Customer on this Merchant's account.
     */
    public function resolveCustomer(Customer $customer, Merchant $merchant): VaultedCustomer;

    /**
     * Begin attaching a card without charging — returns the ticket the browser confirms.
     */
    public function beginSetup(Customer $customer, Merchant $merchant): SetupTicket;

    /**
     * The Customer's saved cards on this Merchant's account.
     *
     * @return array<int, VaultedPaymentMethod>
     */
    public function paymentMethods(Customer $customer, Merchant $merchant): array;

    public function setDefaultPaymentMethod(Customer $customer, Merchant $merchant, string $paymentMethodRef): void;

    public function forgetPaymentMethod(Customer $customer, Merchant $merchant, string $paymentMethodRef): void;
}
