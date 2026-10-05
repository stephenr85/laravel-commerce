<?php

namespace Rushing\Commerce\Exceptions;

use RuntimeException;

/**
 * A Stripe SDK request was made while `commerce.driver` is `fake`. Something collects money around the rail (usually a
 * direct Cashier call); under the fake rail it fails here rather than reaching api.stripe.com.
 */
class StripeDisabledOnFakeRail extends RuntimeException
{
    public static function for(string $method, string $url): self
    {
        return new self(sprintf(
            'fake rail: Stripe disabled (%s %s). commerce.driver is `fake`, so no request reaches Stripe; '
            .'this money path calls Stripe directly instead of going through MoneyIn.',
            strtoupper($method),
            $url,
        ));
    }
}
