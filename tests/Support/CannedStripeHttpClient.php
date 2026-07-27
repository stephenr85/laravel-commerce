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

    /**
     * When set, the next PaymentIntent create returns a 402 error body instead of a
     * success — the real SDK then throws the matching exception (a `card_error` type
     * becomes a CardException). Shape: ['type' => 'card_error', 'code' => …,
     * 'decline_code' => …, 'payment_intent' => ['id' => …]]. Exercises the off-session
     * decline / step-up path with no live Stripe.
     *
     * @var array<string, mixed>|null
     */
    public ?array $paymentIntentError = null;

    /** @var array<int, array<string, mixed>> Per-request options (idempotency key, etc.). */
    public array $options = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1')
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params, 'headers' => $headers];

        if ($this->paymentIntentError !== null && str_contains($absUrl, '/v1/payment_intents')) {
            return [json_encode(['error' => $this->paymentIntentError]), 402, []];
        }

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
