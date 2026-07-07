<?php

namespace Rushing\Commerce\Contracts;

use Rushing\Commerce\Data\Merchant;
use Stripe\StripeClient;

/**
 * Produces a Stripe client bound to a Merchant's own credentials. Keeping this a
 * seam is what makes "never merchant-of-record" enforceable: each Merchant's
 * secret is resolved here (from config, or per-tenant `TenantConfig` in a host),
 * never shared, and a test can hand back a client wired to a canned HTTP transport.
 */
interface StripeClientFactory
{
    public function for(Merchant $merchant): StripeClient;
}
