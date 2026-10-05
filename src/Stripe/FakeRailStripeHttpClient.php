<?php

namespace Rushing\Commerce\Stripe;

use Rushing\Commerce\Exceptions\StripeDisabledOnFakeRail;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;

/**
 * stripe-php's transport while the money-in rail is `fake`. Cashier and every other Stripe SDK caller send through
 * stripe-php's own HTTP client, which Laravel's `Http::fake()` cannot see, so this is the one place that can stop them
 * all. A request made while `commerce.driver` is `fake` throws {@see StripeDisabledOnFakeRail} and sends nothing.
 * Otherwise (a test that switches the rail at runtime) the request goes to the wrapped transport, stripe-php's default.
 *
 * Installed by the service provider only when the rail is `fake` at boot, so a host on a real rail keeps stripe-php's
 * client untouched.
 */
class FakeRailStripeHttpClient implements ClientInterface
{
    public function __construct(private ?ClientInterface $transport = null) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1')
    {
        if (config('commerce.driver') === 'fake') {
            throw StripeDisabledOnFakeRail::for((string) $method, (string) $absUrl);
        }

        return ($this->transport ?? CurlClient::instance())->request($method, $absUrl, $headers, $params, $hasFile, $apiMode);
    }
}
