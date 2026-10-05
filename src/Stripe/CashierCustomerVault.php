<?php

namespace Rushing\Commerce\Stripe;

use Rushing\Commerce\Contracts\CustomerVault;
use Rushing\Commerce\Data\Customer;
use Rushing\Commerce\Data\Merchant;
use Rushing\Commerce\Data\SetupTicket;
use Rushing\Commerce\Data\VaultedCustomer;
use Rushing\Commerce\Data\VaultedPaymentMethod;
use Rushing\Commerce\Events\PaymentMethodsChanged;

/**
 * The card vault on Stripe, inside the Stripe adapter (purchase-walkthrough BUY-02 part B4, rule BUY-1). Moved from the
 * flagship's App\Billing\CashierCustomerVault UNCHANGED in what it asks Cashier and Stripe: the same Cashier methods on
 * the same billable, so the same requests and the same `stripe_id` / card columns (a parity test pins it). The host
 * supplies only which model is the billable ({@see BillableResolver}).
 */
class CashierCustomerVault implements CustomerVault
{
    public function __construct(private BillableResolver $billables) {}

    public function resolveCustomer(Customer $customer, Merchant $merchant): VaultedCustomer
    {
        $account = $this->billables->forCustomer($customer);

        return new VaultedCustomer(
            customerId: $customer->id,
            providerRef: $account->createOrGetStripeCustomer()->id,
            merchantId: $merchant->id,
        );
    }

    public function beginSetup(Customer $customer, Merchant $merchant): SetupTicket
    {
        $account = $this->billables->forCustomer($customer);
        $account->createOrGetStripeCustomer();

        // usage=off_session authenticates the card up front so later reloads qualify as merchant-initiated and can
        // claim SCA exemptions.
        $intent = $account->createSetupIntent(['usage' => 'off_session']);

        return new SetupTicket(
            clientSecret: (string) $intent->client_secret,
            providerRef: $intent->id,
            merchantId: $merchant->id,
        );
    }

    public function paymentMethods(Customer $customer, Merchant $merchant): array
    {
        $account = $this->billables->forCustomer($customer);

        if (! $account->hasStripeId()) {
            return [];
        }

        $default = $account->defaultPaymentMethod();

        return $account->paymentMethods()->map(fn ($pm) => new VaultedPaymentMethod(
            ref: $pm->id,
            brand: (string) $pm->card->brand,
            last4: (string) $pm->card->last4,
            expMonth: (int) $pm->card->exp_month,
            expYear: (int) $pm->card->exp_year,
            isDefault: $default !== null && $default->id === $pm->id,
        ))->all();
    }

    public function setDefaultPaymentMethod(Customer $customer, Merchant $merchant, string $paymentMethodRef): void
    {
        $this->billables->forCustomer($customer)->updateDefaultPaymentMethod($paymentMethodRef);

        // The card changed: whoever cached "has a card" (auto-reload) drops it now.
        PaymentMethodsChanged::dispatch($customer);
    }

    public function forgetPaymentMethod(Customer $customer, Merchant $merchant, string $paymentMethodRef): void
    {
        $this->billables->forCustomer($customer)->findPaymentMethod($paymentMethodRef)?->delete();

        PaymentMethodsChanged::dispatch($customer);
    }
}
