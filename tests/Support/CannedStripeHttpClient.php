<?php

namespace Rushing\Commerce\Tests\Support;

use Stripe\HttpClient\ClientInterface;

/**
 * A canned Stripe transport: returns fixed JSON for the two endpoints the money-in
 * driver touches, so the real Stripe SDK object graph (and our mapping of it) runs
 * with no network and no live keys. Installed globally via ApiRequestor::setHttpClient.
 */
class CannedStripeHttpClient implements ClientInterface
{
    /** @var array<int, array{method: string, url: string, params: array}> */
    public array $requests = [];

    /**
     * The status the next PaymentIntent comes back with. Flip to 'requires_action' to
     * exercise the SCA path without a live 3-D-Secure challenge.
     */
    public string $paymentIntentStatus = 'succeeded';

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1')
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];

        $body = match (true) {
            str_contains($absUrl, '/v1/payment_intents') => json_encode([
                'id' => 'pi_fake_123',
                'object' => 'payment_intent',
                'status' => $this->paymentIntentStatus,
                'amount' => $params['amount'] ?? 0,
                'currency' => $params['currency'] ?? 'usd',
                'client_secret' => 'pi_fake_123_secret',
            ]),
            str_contains($absUrl, '/v1/setup_intents') => json_encode([
                'id' => 'seti_fake_123',
                'object' => 'setup_intent',
                'status' => 'requires_payment_method',
                'client_secret' => 'seti_fake_123_secret',
            ]),
            str_contains($absUrl, '/v1/customers') => json_encode([
                'id' => 'cus_fake_123',
                'object' => 'customer',
            ]),
            str_contains($absUrl, '/v1/refunds') => json_encode([
                'id' => 're_fake_123',
                'object' => 'refund',
                'status' => 'succeeded',
                'payment_intent' => $params['payment_intent'] ?? null,
            ]),
            default => json_encode(['id' => 'obj_fake', 'object' => 'unknown']),
        };

        return [$body, 200, []];
    }
}
