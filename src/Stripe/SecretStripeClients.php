<?php

namespace Rushing\Commerce\Stripe;

use RuntimeException;
use Rushing\Commerce\Data\Merchant;
use Stripe\StripeClient;

/**
 * A StripeClientFactory whose only host-specific part is where a merchant's secret lives (purchase-walkthrough BUY-02
 * part B4b, rule BUY-1): the SDK client is built here, inside the Stripe adapter, so a host's credential lookup names
 * no `Stripe\` class.
 */
abstract class SecretStripeClients implements StripeClientFactory
{
    /** The merchant's Stripe secret, or null/'' when it has none. */
    abstract protected function secretFor(Merchant $merchant): ?string;

    /** Why a merchant without a secret cannot collect, in the host's words. */
    abstract protected function missingSecretMessage(Merchant $merchant): string;

    public function for(Merchant $merchant): StripeClient
    {
        $secret = $this->secretFor($merchant);
        if ($secret === null || $secret === '') {
            throw new RuntimeException($this->missingSecretMessage($merchant));
        }

        return new StripeClient($secret);
    }
}
