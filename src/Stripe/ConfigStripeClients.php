<?php

namespace Rushing\Commerce\Stripe;

use RuntimeException;
use Rushing\Commerce\Contracts\StripeClientFactory;
use Rushing\Commerce\Data\Merchant;
use Stripe\StripeClient;

/**
 * Builds a Stripe client from the app's single configured secret. A tenancy-aware
 * host overrides this to resolve the secret from the billing party's own
 * `TenantConfig` (`services.stripe.secret`), so the account used is the Merchant's,
 * never the platform's.
 */
class ConfigStripeClients implements StripeClientFactory
{
    public function for(Merchant $merchant): StripeClient
    {
        $secret = config('commerce.stripe.secret');

        if (blank($secret)) {
            throw new RuntimeException(
                "No Stripe secret is configured for merchant [{$merchant->id}]. Set commerce.stripe.secret "
                .'(or provide a tenancy-aware StripeClientFactory that resolves the billing party\'s key).'
            );
        }

        return new StripeClient((string) $secret);
    }
}
