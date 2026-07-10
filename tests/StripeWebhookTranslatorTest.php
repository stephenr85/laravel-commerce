<?php

use Rushing\Commerce\Data\InvoiceRef;
use Rushing\Commerce\Data\SubscriptionState;
use Rushing\Commerce\Enums\PurchaseKind;
use Rushing\Commerce\Stripe\StripeWebhookTranslator;

function subPayload(string $type, string $status = 'active'): array
{
    return ['type' => $type, 'data' => ['object' => [
        'customer' => 'cus_1',
        'status' => $status,
        'current_period_end' => 1234567890,
        'items' => ['data' => [['price' => ['id' => 'price_1']]]],
    ]]];
}

it('translates a subscription lifecycle event into neutral state', function () {
    $state = (new StripeWebhookTranslator)->subscription(subPayload('customer.subscription.created'));

    expect($state)->toBeInstanceOf(SubscriptionState::class)
        ->and($state->customerRef)->toBe('cus_1')
        ->and($state->priceRef)->toBe('price_1')
        ->and($state->active)->toBeTrue()
        ->and($state->currentPeriodEnd)->toBe(1234567890);
});

it('marks a deleted subscription inactive regardless of status', function () {
    $state = (new StripeWebhookTranslator)->subscription(subPayload('customer.subscription.deleted', 'active'));

    expect($state->active)->toBeFalse();
});

it('returns null for non-subscription events', function () {
    expect((new StripeWebhookTranslator)->subscription(['type' => 'invoice.paid']))->toBeNull();
});

it('translates invoice.paid into an InvoiceRef', function () {
    $ref = (new StripeWebhookTranslator)->invoicePaid(['type' => 'invoice.paid', 'data' => ['object' => ['id' => 'in_1']]]);

    expect($ref)->toBeInstanceOf(InvoiceRef::class)
        ->and($ref->providerRef)->toBe('in_1')
        ->and($ref->paid)->toBeTrue();
});

it('translates a paid credit-topup session into a CreditTopup purchase', function () {
    $purchase = (new StripeWebhookTranslator)->creditTopup(['type' => 'checkout.session.completed', 'data' => ['object' => [
        'id' => 'cs_1',
        'payment_status' => 'paid',
        'amount_total' => 2500,
        'currency' => 'usd',
        'metadata' => ['kind' => 'credit_topup', 'party_ref' => 'tenant-x'],
    ]]]);

    expect($purchase->kind)->toBe(PurchaseKind::CreditTopup)
        ->and($purchase->beneficiaryId)->toBe('tenant-x')
        ->and($purchase->payment->providerRef)->toBe('cs_1')
        ->and($purchase->payment->amount->minorUnits)->toBe(2500);
});

it('returns null for an unpaid or non-topup session', function () {
    $t = new StripeWebhookTranslator;

    $unpaid = $t->creditTopup(['type' => 'checkout.session.completed', 'data' => ['object' => [
        'id' => 'cs', 'payment_status' => 'unpaid', 'amount_total' => 100,
        'metadata' => ['kind' => 'credit_topup', 'party_ref' => 'x'],
    ]]]);

    $notTopup = $t->creditTopup(['type' => 'checkout.session.completed', 'data' => ['object' => [
        'id' => 'cs', 'payment_status' => 'paid', 'amount_total' => 100, 'metadata' => ['kind' => 'other'],
    ]]]);

    expect($unpaid)->toBeNull()->and($notTopup)->toBeNull();
});
