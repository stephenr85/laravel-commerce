<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Rushing\Commerce\Data\Beneficiary;
use Rushing\Commerce\Data\Customer;
use Rushing\Commerce\Data\LineItem;
use Rushing\Commerce\Data\Money;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Enums\PurchaseKind;
use Rushing\Commerce\Enums\RedemptionStatus;
use Rushing\Commerce\Events\GiftDelivered;
use Rushing\Commerce\Events\GiftIssued;
use Rushing\Commerce\Events\GiftRedeemed;
use Rushing\Commerce\Exceptions\RedemptionException;
use Rushing\Commerce\Gifts;
use Rushing\Commerce\Models\Redemption;

it('pays for a Gift with payer != Beneficiary and issues a redemption code', function () {
    Event::fake([GiftIssued::class]);

    $gift = app(Gifts::class)->purchase(
        order: testOrder(minorUnits: 900),
        beneficiary: new Beneficiary(deliverTo: 'friend@example.test'),
    );

    expect($gift->purchase->payment->succeeded())->toBeTrue()
        ->and($gift->redemptionCode)->not->toBeEmpty();

    $redemption = Redemption::query()->where('code', $gift->redemptionCode)->firstOrFail();

    expect($redemption->status)->toBe(RedemptionStatus::Issued)
        ->and($redemption->payer_id)->toBe('cus_1')
        ->and($redemption->beneficiary_party_id)->toBeNull()
        ->and($redemption->deliver_to)->toBe('friend@example.test');

    Event::assertDispatched(GiftIssued::class);
});

it('rejects a Gift whose Beneficiary is the payer', function () {
    expect(fn () => app(Gifts::class)->purchase(
        order: testOrder(),
        beneficiary: new Beneficiary(partyId: 'cus_1'),
    ))->toThrow(RedemptionException::class);
});

it('claims a code without a pre-existing account and binds redeemedBy at claim time', function () {
    Event::fake([GiftRedeemed::class]);

    $gift = app(Gifts::class)->purchase(testOrder(), new Beneficiary);

    $redemption = app(Gifts::class)->redeem($gift->redemptionCode, redeemedBy: 'new_signup_42');

    expect($redemption->status)->toBe(RedemptionStatus::Redeemed)
        ->and($redemption->redeemed_by)->toBe('new_signup_42')
        ->and($redemption->redeemed_at)->not->toBeNull();

    Event::assertDispatched(
        GiftRedeemed::class,
        fn (GiftRedeemed $e) => $e->redemption->redeemed_by === 'new_signup_42',
    );
});

it('transitions issued -> delivered -> redeemed and fires each event', function () {
    Event::fake([GiftDelivered::class, GiftRedeemed::class]);

    $gift = app(Gifts::class)->purchase(testOrder(), new Beneficiary(deliverTo: 'x@example.test'));
    $redemption = Redemption::query()->where('code', $gift->redemptionCode)->firstOrFail();

    app(Gifts::class)->deliver($redemption);
    expect($redemption->fresh()->status)->toBe(RedemptionStatus::Delivered);

    app(Gifts::class)->redeem($gift->redemptionCode, 'someone');
    expect($redemption->fresh()->status)->toBe(RedemptionStatus::Redeemed);

    Event::assertDispatched(GiftDelivered::class);
    Event::assertDispatched(GiftRedeemed::class);
});

it('expires an unclaimed code past its window and refuses to redeem it', function () {
    $gift = app(Gifts::class)->purchase(testOrder(), new Beneficiary);
    Redemption::query()->where('code', $gift->redemptionCode)
        ->update(['expires_at' => Carbon::now()->subDay()]);

    expect(fn () => app(Gifts::class)->redeem($gift->redemptionCode, 'late'))
        ->toThrow(RedemptionException::class);

    $swept = app(Gifts::class)->expireStale();

    expect(Redemption::query()->where('code', $gift->redemptionCode)->first()->status)
        ->toBe(RedemptionStatus::Expired)
        ->and($swept)->toBeGreaterThanOrEqual(0);
});

it('gifts a Credit pack through the same spine with no special-cased form', function () {
    $creditPack = Order::for(
        customer: new Customer(id: 'cus_1'),
        lineItems: [LineItem::for('100-credit pack', Money::of(1000))],
        kind: PurchaseKind::CreditTopup,
    );

    $gift = app(Gifts::class)->purchase($creditPack, new Beneficiary(deliverTo: 'g@example.test'));

    expect($gift->purchase->kind)->toBe(PurchaseKind::CreditTopup);

    $redemption = app(Gifts::class)->redeem($gift->redemptionCode, 'recipient');

    expect($redemption->purchase()->kind)->toBe(PurchaseKind::CreditTopup)
        ->and($redemption->status)->toBe(RedemptionStatus::Redeemed);
});
