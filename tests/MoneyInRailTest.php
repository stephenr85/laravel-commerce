<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Rushing\Commerce\Contracts\CustomerVault;
use Rushing\Commerce\Data\Customer;
use Rushing\Commerce\Data\LineItem;
use Rushing\Commerce\Data\Money;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Enums\CustodyPosture;
use Rushing\Commerce\Enums\PaymentActionKind;
use Rushing\Commerce\Enums\PaymentStatus;
use Rushing\Commerce\Enums\PurchaseKind;
use Rushing\Commerce\Events\PurchaseCompleted;
use Rushing\Commerce\Exceptions\MoneyInUnavailable;
use Rushing\Commerce\MoneyIn;
use Rushing\Commerce\Tests\Support\CannedStripeHttpClient;
use Rushing\Commerce\Tests\Support\RecordingCustomerVault;
use Stripe\ApiRequestor;

/*
 * The rail (purchase-walkthrough BUY-02, rule BUY-1; PF-04 Phase B, ruling C-11): every path that starts collecting
 * money is an Order through MoneyIn::place, or a portal through MoneyIn::manage. A hosted leg comes back as ONE field,
 * `Payment.action` {redirect | embedded}, never a sibling contract; `posture()` reads only `commerce.custody`; the
 * `none` rail refuses. Zero outbound HTTP outside the stubbed Stripe transport, by assertion.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->stripe = new CannedStripeHttpClient;
    ApiRequestor::setHttpClient($this->stripe);
    config()->set('commerce.stripe.secret', 'sk_test_fake');
    app()->instance(CustomerVault::class, new RecordingCustomerVault);

    // A host mounts the fake rail's pages itself, inside `web` (as it mounts the ACP routes); `web` encrypts cookies.
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    Route::middleware('web')->group(fn () => Route::commerceFakeRailRoutes());
    app('router')->getRoutes()->refreshNameLookups();
});

function topupOrder(?PaymentActionKind $hosted = PaymentActionKind::Embedded): Order
{
    return Order::for(
        customer: new Customer(id: 'tenant-acme', name: 'Acme', email: 'billing@acme.test'),
        lineItems: [LineItem::for('Generation credits', Money::of(2500))],
        kind: PurchaseKind::CreditTopup,
        hosted: $hosted,
        returnUrl: 'https://acme.test/ui/settings/credits?checkout={CHECKOUT_SESSION_ID}',
    );
}

it('§2.4 probe: an embedded credit checkout is RequiresAction carrying one embedded action with the client secret', function () {
    Event::fake([PurchaseCompleted::class]);

    $purchase = app(MoneyIn::class)->place(topupOrder(), 'stripe');

    expect($purchase->payment->status)->toBe(PaymentStatus::RequiresAction)
        ->and($purchase->payment->action?->kind)->toBe(PaymentActionKind::Embedded)
        ->and($purchase->payment->action?->clientSecret)->toBe('cs_fake_123_secret')
        ->and($purchase->payment->action?->url)->toBeNull()
        ->and($purchase->payment->providerRef)->toBe('cs_fake_123');

    // It opened an embedded Checkout Session carrying exactly what the webhook translator reads back: the kind selects
    // the credit-topup path and party_ref is the Wallet beneficiary (StripeWebhookTranslator::creditTopup).
    $request = collect($this->stripe->requests)->first(fn ($r) => str_contains($r['url'], '/v1/checkout/sessions'));
    expect($request['params']['ui_mode'])->toBe('embedded')
        ->and($request['params']['mode'])->toBe('payment')
        ->and($request['params']['return_url'])->toContain('{CHECKOUT_SESSION_ID}')
        ->and($request['params']['customer'])->toBe('cus_vault_tenant-acme')
        ->and($request['params']['metadata']['kind'])->toBe('credit_topup')
        ->and($request['params']['metadata']['party_ref'])->toBe('tenant-acme')
        ->and($request['params']['line_items'][0]['price_data']['unit_amount'])->toBe(2500);

    // Nothing is funded until the session settles: no PurchaseCompleted on a RequiresAction.
    Event::assertNotDispatched(PurchaseCompleted::class);
});

it('opens a redirect-mode hosted checkout as a redirect action with the session url', function () {
    $payment = app(MoneyIn::class)->place(topupOrder(PaymentActionKind::Redirect), 'stripe')->payment;

    expect($payment->status)->toBe(PaymentStatus::RequiresAction)
        ->and($payment->action?->kind)->toBe(PaymentActionKind::Redirect)
        ->and($payment->action?->url)->toBe('https://checkout.stripe.test/c/cs_fake_123');
    $request = collect($this->stripe->requests)->first(fn ($r) => str_contains($r['url'], '/v1/checkout/sessions'));
    expect($request['params'])->not->toHaveKey('ui_mode')
        ->and($request['params']['success_url'])->toContain('{CHECKOUT_SESSION_ID}');
});

it('answers a hosted leg on the fake rail with a local confirm page and no outbound call', function () {
    Event::fake([PurchaseCompleted::class]);

    $payment = app(MoneyIn::class)->place(topupOrder(), 'fake')->payment;

    expect($payment->status)->toBe(PaymentStatus::RequiresAction)
        ->and($payment->action?->kind)->toBe(PaymentActionKind::Redirect)
        ->and($payment->action?->url)->toContain('/commerce/fake/confirm/');
    expect($this->stripe->requests)->toBe([]);
    Event::assertNotDispatched(PurchaseCompleted::class);
});

it('settles the fake confirm page into the same PurchaseCompleted a webhook would, then returns to the caller', function () {
    Event::fake([PurchaseCompleted::class]);
    $purchase = app(MoneyIn::class)->place(topupOrder(), 'fake', beneficiaryId: 'tenant-acme');
    $confirm = parse_url($purchase->payment->action->url, PHP_URL_PATH);

    $this->get($confirm)->assertOk()->assertSee('Confirm');
    $this->post($confirm)->assertRedirect();

    Event::assertDispatched(PurchaseCompleted::class, fn (PurchaseCompleted $e) => $e->purchase->kind === PurchaseKind::CreditTopup
        && $e->purchase->beneficiaryId === 'tenant-acme'
        && $e->purchase->payment->succeeded());

    // One-shot: a replayed confirm settles nothing twice.
    $this->post($confirm)->assertNotFound();
    Event::assertDispatchedTimes(PurchaseCompleted::class, 1);
});

it('captures every purchase kind on each rail, the none rail refusing', function (string $driver, PurchaseKind $kind) {
    $order = Order::for(
        customer: new Customer(id: 'party-1', name: 'Party', email: 'party@example.test'),
        lineItems: [LineItem::for('Thing', Money::of(900))],
        kind: $kind,
    );

    if ($driver === 'none') {
        expect(fn () => app(MoneyIn::class)->place($order, 'none'))->toThrow(MoneyInUnavailable::class);

        return;
    }

    $payment = app(MoneyIn::class)->place($order, $driver)->payment;
    expect($payment->driver)->toBe($driver)
        ->and($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->action)->toBeNull();
})->with(['fake', 'none', 'stripe'])->with(fn () => PurchaseKind::cases());

it('manages billing through the rail: a portal redirect on stripe, a local page on fake, a refusal on none', function () {
    $customer = new Customer(id: 'tenant-acme', name: 'Acme', email: 'billing@acme.test');

    $stripe = app(MoneyIn::class)->manage($customer, 'https://acme.test/ui/settings/billing', 'stripe');
    expect($stripe->kind)->toBe(PaymentActionKind::Redirect)
        ->and($stripe->url)->toBe('https://billing.stripe.test/p/session/bps_fake_123');
    $request = collect($this->stripe->requests)->first(fn ($r) => str_contains($r['url'], '/v1/billing_portal/sessions'));
    expect($request['params']['customer'])->toBe('cus_vault_tenant-acme')
        ->and($request['params']['return_url'])->toBe('https://acme.test/ui/settings/billing');

    $fake = app(MoneyIn::class)->manage($customer, 'https://acme.test/ui/settings/billing', 'fake');
    expect($fake->kind)->toBe(PaymentActionKind::Redirect)
        ->and($fake->url)->not->toContain('stripe');

    expect(fn () => app(MoneyIn::class)->manage($customer, 'https://acme.test/x', 'none'))->toThrow(MoneyInUnavailable::class);
});

it('reads posture from commerce.custody alone, never from the driver', function () {
    config()->set('commerce.driver', 'stripe');
    config()->set('commerce.custody', 'none');
    expect(app(MoneyIn::class)->posture())->toBe(CustodyPosture::None);

    config()->set('commerce.driver', 'fake');
    config()->set('commerce.custody', 'direct');
    expect(app(MoneyIn::class)->posture())->toBe(CustodyPosture::Direct);

    // Undeclared custody keeps today's behaviour: purchases are offered through hosted legs.
    config()->set('commerce.custody', null);
    expect(app(MoneyIn::class)->posture())->toBe(CustodyPosture::Hosted);
});

it('C-3: custody none selects the none rail whatever commerce.driver or the caller names', function () {
    config()->set('commerce.custody', 'none');
    config()->set('commerce.driver', 'stripe');

    expect(fn () => app(MoneyIn::class)->place(topupOrder(null)))->toThrow(MoneyInUnavailable::class)
        ->and(fn () => app(MoneyIn::class)->place(topupOrder(null), 'stripe'))->toThrow(MoneyInUnavailable::class)
        ->and(fn () => app(MoneyIn::class)->place(topupOrder(null), 'fake'))->toThrow(MoneyInUnavailable::class);
    expect($this->stripe->requests)->toBe([]);
});

it('opens a hosted leg only for a kind that has a settling translator, on stripe and fake alike', function (string $driver, PurchaseKind $kind) {
    // build.qa: StripeWebhookTranslator settles only credit_topup from checkout.session.completed. A hosted plan or market
    // leg would charge and grant nothing, while the fake confirm page settled it: the fake must not prove what Stripe
    // cannot do (BUY-3). Both rails refuse until a translator exists for the kind.
    $order = Order::for(
        customer: new Customer(id: 'party-1', name: 'Party', email: 'party@example.test'),
        lineItems: [LineItem::for('Thing', Money::of(900))],
        kind: $kind,
        hosted: PaymentActionKind::Embedded,
        returnUrl: 'https://acme.test/back',
    );

    expect(fn () => app(MoneyIn::class)->place($order, $driver))->toThrow(InvalidArgumentException::class, $kind->value);
    expect($this->stripe->requests)->toBe([]);
})->with(['stripe', 'fake'])->with([PurchaseKind::Perpetual, PurchaseKind::Subscription]);

it('sends the fake portal back only to a path on this app, never a script or another origin', function () {
    $portal = fn (string $return) => $this->get(route('commerce.fake.portal', ['return' => $return]))->assertOk()->getContent();

    expect($portal('/ui/settings/billing'))->toContain('href="/ui/settings/billing"')
        ->and($portal('javascript:alert(1)'))->not->toContain('javascript:')
        ->and($portal('https://evil.example/x'))->not->toContain('evil.example')
        ->and($portal('//evil.example/x'))->not->toContain('evil.example')
        ->and($portal('/\\evil.example/x'))->not->toContain('evil.example')
        // Browsers strip ASCII tab and newline from URLs, so `/\t/evil` would become `//evil` (build.qa; the A2 class).
        ->and($portal("/\t/evil.example/x"))->not->toContain('evil.example')
        ->and($portal("/\n/evil.example/x"))->not->toContain('evil.example')
        ->and($portal("/\r/evil.example/x"))->not->toContain('evil.example');
});
