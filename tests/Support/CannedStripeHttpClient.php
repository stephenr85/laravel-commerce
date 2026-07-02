<?php

declare(strict_types=1);

namespace Rushing\Commerce\Tests\Support;

use Stripe\HttpClient\ClientInterface;

/**
 * A canned Stripe transport: returns fixed JSON for the two endpoints the money-in
 * driver touches, so the real Stripe SDK object graph (and our mapping of it) runs
 * with no network and no live keys. Installed globally via ApiRequestor::setHttpClient.
 */
final class CannedStripeHttpClient implements ClientInterface
{
    /** @var array<int, array{method: string, url: string, params: array}> */
    public array $requests = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1')
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];

        $body = match (true) {
            str_contains($absUrl, '/v1/payment_intents') => json_encode([
                'id' => 'pi_fake_123',
                'object' => 'payment_intent',
                'status' => 'succeeded',
                'amount' => $params['amount'] ?? 0,
                'currency' => $params['currency'] ?? 'usd',
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
