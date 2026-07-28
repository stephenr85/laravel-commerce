<?php

use Illuminate\Support\Facades\Route;
use Rushing\Commerce\Acp\AgenticCheckout;
use Rushing\Commerce\Acp\Data\AgentProvenance;
use Rushing\Commerce\Acp\Data\DelegatePayment;
use Rushing\Commerce\Acp\Enums\CheckoutStatus;
use Rushing\Commerce\Acp\Exceptions\CheckoutException;
use Rushing\Commerce\Acp\Models\AcpOrder;
use Rushing\Commerce\Drivers\FakeDriver;

beforeEach(function () {
    config()->set('commerce.driver', 'fake');
    config()->set('commerce.acp.fixtures', [
        'demo-offer' => ['name' => 'Demo Offer', 'amount' => 900, 'currency' => 'USD'],
    ]);

    // Mount the protocol under a bare group so the HTTP path is exercised end to end.
    Route::middleware('api')->group(fn () => Route::commerceAcpRoutes());
});

afterEach(fn () => FakeDriver::clearFakes());

function checkout(): AgenticCheckout
{
    return app(AgenticCheckout::class);
}

it('creates a payable session from a resolved fixture offer', function () {
    $session = checkout()->create([['item_ref' => 'demo-offer', 'quantity' => 2]]);

    expect($session->status)->toBe(CheckoutStatus::ReadyForPayment)
        ->and($session->lineItems)->toHaveCount(1)
        ->and($session->lineItems[0]->itemRef)->toBe('demo-offer')
        ->and($session->lineItems[0]->quantity)->toBe(2)
        ->and($session->total->minorUnits)->toBe(1800)
        ->and($session->order)->toBeNull();

    // Persisted and retrievable across the create/complete boundary.
    expect(checkout()->retrieve($session->id))->not->toBeNull();
});

it('rejects a session against an unresolvable offer', function () {
    expect(fn () => checkout()->create([['item_ref' => 'nope']]))
        ->toThrow(CheckoutException::class);
});

it('completes checkout: charges the delegate token, records a minimal order + provenance', function () {
    $provenance = new AgentProvenance(agentId: 'agent_42', sessionId: 'sess_7', reason: 'gift for a friend');
    $session = checkout()->create([['item_ref' => 'demo-offer']], $provenance);

    $completed = checkout()->complete(
        $session->id,
        new DelegatePayment(token: 'spt_fixture_123', provider: 'stripe'),
    );

    expect($completed->status)->toBe(CheckoutStatus::Completed)
        ->and($completed->order)->not->toBeNull()
        ->and($completed->order->paymentId)->not->toBeEmpty();

    $order = AcpOrder::query()->where('checkout_session_id', $session->id)->firstOrFail();
    expect($order->amount_minor)->toBe(900)
        ->and($order->item_refs[0]['ref'])->toBe('demo-offer')
        ->and($order->driver)->toBe('fake')
        // The agent-provenance trace is recorded against the order.
        ->and($order->provenance['agentId'])->toBe('agent_42')
        ->and($order->provenance['sessionId'])->toBe('sess_7')
        ->and($order->provenance['reason'])->toBe('gift for a friend');
});

it('records no order when the delegate charge is declined', function () {
    FakeDriver::fakeDecline('card_declined');
    $session = checkout()->create([['item_ref' => 'demo-offer']]);

    expect(fn () => checkout()->complete($session->id, new DelegatePayment(token: 'spt_x', provider: 'stripe')))
        ->toThrow(CheckoutException::class);

    expect(AcpOrder::query()->where('checkout_session_id', $session->id)->exists())->toBeFalse();

    // The session stays payable so the agent can retry with another instrument.
    expect(checkout()->retrieve($session->id)->status)->toBe(CheckoutStatus::ReadyForPayment);
});

it('drives the full loop over HTTP in the ACP protocol shape', function () {
    $create = $this->postJson('agentic-commerce/checkout_sessions', [
        'items' => [['item_ref' => 'demo-offer']],
    ], ['X-Agent-Id' => 'agent_http', 'X-Agent-Session-Id' => 'sess_http']);

    $create->assertCreated()
        ->assertJsonPath('status', 'ready_for_payment')
        ->assertJsonPath('total.minorUnits', 900);

    $id = $create->json('id');

    $this->getJson("agentic-commerce/checkout_sessions/{$id}")
        ->assertOk()
        ->assertJsonPath('id', $id);

    $complete = $this->postJson("agentic-commerce/checkout_sessions/{$id}/complete", [
        'payment_data' => ['token' => 'spt_http', 'provider' => 'stripe'],
    ], ['X-Agent-Id' => 'agent_http']);

    $complete->assertOk()->assertJsonPath('status', 'completed');
    expect($complete->json('order.id'))->not->toBeEmpty();

    $order = AcpOrder::query()->where('checkout_session_id', $id)->firstOrFail();
    expect($order->provenance['agentId'])->toBe('agent_http');
});

it('is registered under brand-blind names at the configured root path', function () {
    $routes = collect(Route::getRoutes()->getRoutes());
    $names = $routes->map->getName()->filter()->all();

    expect($names)->toContain('commerce.checkout.create', 'commerce.checkout.show', 'commerce.checkout.complete');

    $create = $routes->first(fn ($r) => $r->getName() === 'commerce.checkout.create');
    expect($create->uri())->toBe('agentic-commerce/checkout_sessions');
});
