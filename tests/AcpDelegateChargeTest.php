<?php

use Rushing\Commerce\Acp\AgenticCheckout;
use Rushing\Commerce\Acp\Data\DelegatePayment;
use Rushing\Commerce\Acp\Enums\CheckoutStatus;
use Rushing\Commerce\Acp\Exceptions\CheckoutException;
use Rushing\Commerce\Acp\Models\AcpOrder;
use Rushing\Commerce\Tests\Support\CannedStripeHttpClient;
use Stripe\ApiRequestor;

/*
 * agentic-commerce-sell-side, issue 03 — real delegate-token charge, satellite as merchant-of-record.
 * The ACP `complete` charges the delegate token through the existing money-in seam with the `stripe`
 * driver: a delegated/shared token is charged directly on the merchant's own account (no customer of
 * ours, off-session), and a receipt is recorded against the order. No live Stripe — the canned
 * transport runs the real SDK object graph.
 */

beforeEach(function () {
    config()->set('commerce.driver', 'stripe');
    config()->set('commerce.stripe.secret', 'sk_test_fake');
    config()->set('commerce.acp.fixtures', [
        'demo-offer' => ['name' => 'Demo Offer', 'amount' => 1500, 'currency' => 'USD'],
    ]);

    $this->stripe = new CannedStripeHttpClient;
    ApiRequestor::setHttpClient($this->stripe);
});

function acpCheckout(): AgenticCheckout
{
    return app(AgenticCheckout::class);
}

it('charges the delegate token on the merchant account with no customer of ours, off-session', function () {
    $session = acpCheckout()->create([['item_ref' => 'demo-offer']]);

    $completed = acpCheckout()->complete(
        $session->id,
        new DelegatePayment(token: 'spt_delegate_live', provider: 'stripe'),
    );

    expect($completed->status)->toBe(CheckoutStatus::Completed)
        ->and($completed->order->providerRef)->toBe('pi_fake_123');

    // The PaymentIntent charged the delegate token directly — merchant-of-record, no customer,
    // off-session (no buyer present for a step-up). Stripe's SDK serializes bools to 'true'/'false'.
    $params = $this->stripe->requests[0]['params'];
    expect($this->stripe->requests[0]['url'])->toContain('/v1/payment_intents')
        ->and($params['payment_method'])->toBe('spt_delegate_live')
        ->and($params['confirm'])->toBe('true')
        ->and($params['off_session'])->toBe('true')
        ->and($params['amount'])->toBe(1500)
        ->and($params)->not->toHaveKey('customer');
});

it('records a receipt against the order on a real charge', function () {
    $session = acpCheckout()->create([['item_ref' => 'demo-offer']]);
    acpCheckout()->complete($session->id, new DelegatePayment(token: 'spt_x', provider: 'stripe'));

    $order = AcpOrder::query()->where('checkout_session_id', $session->id)->firstOrFail();
    expect($order->driver)->toBe('stripe')
        ->and($order->provider_ref)->toBe('pi_fake_123')
        // A receipt is recorded against the order (issue 03 acceptance).
        ->and($order->receipt_id)->not->toBeEmpty();
});

it('rejects an invalid/expired delegate token and records no order', function () {
    // Stripe raises a CardException for an expired/invalid token; the driver normalizes it
    // to a failed Payment and the checkout refuses to complete.
    $this->stripe->paymentIntentError = [
        'type' => 'card_error',
        'code' => 'expired_card',
        'decline_code' => 'expired_card',
    ];

    $session = acpCheckout()->create([['item_ref' => 'demo-offer']]);

    expect(fn () => acpCheckout()->complete($session->id, new DelegatePayment(token: 'spt_expired', provider: 'stripe')))
        ->toThrow(CheckoutException::class);

    expect(AcpOrder::query()->where('checkout_session_id', $session->id)->exists())->toBeFalse();
    // The session stays payable so the agent can retry with a fresh token.
    expect(acpCheckout()->retrieve($session->id)->status)->toBe(CheckoutStatus::ReadyForPayment);
});
