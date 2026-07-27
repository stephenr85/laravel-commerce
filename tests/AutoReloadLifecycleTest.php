<?php

use Rushing\Commerce\AutoReload;
use Rushing\Commerce\Data\Money;
use Rushing\Commerce\Data\Payment;
use Rushing\Commerce\Enums\AutoReloadOutcome;
use Rushing\Commerce\Enums\PaymentStatus;
use Rushing\Commerce\Models\AutoReloadAttempt;
use Rushing\Commerce\Models\AutoReloadConfig;

function armedConfig(): AutoReload
{
    $service = new AutoReload;
    $service->configure('tenant-x', 'usd', ['enabled' => true, 'threshold_usd' => 5.0, 'reload_amount_usd' => 25.0]);

    return $service;
}

function configFor(string $party = 'tenant-x'): AutoReloadConfig
{
    return AutoReloadConfig::query()->where('party_id', $party)->where('unit', 'usd')->first();
}

function payment(PaymentStatus $status, ?string $errorCode = null): Payment
{
    return new Payment(
        id: 'pay-1', orderId: 'ord-1', amount: Money::of(2500),
        status: $status, driver: 'fake', providerRef: 'pi_1', errorCode: $errorCode,
    );
}

it('classifies a Payment into the outcome taxonomy', function () {
    expect(AutoReloadOutcome::fromPayment(payment(PaymentStatus::Succeeded)))->toBe(AutoReloadOutcome::Succeeded)
        ->and(AutoReloadOutcome::fromPayment(payment(PaymentStatus::Failed, 'insufficient_funds')))->toBe(AutoReloadOutcome::Declined)
        ->and(AutoReloadOutcome::fromPayment(payment(PaymentStatus::RequiresAction, 'authentication_required')))->toBe(AutoReloadOutcome::ScaRequired)
        ->and(AutoReloadOutcome::fromPayment(payment(PaymentStatus::Failed, 'authentication_required')))->toBe(AutoReloadOutcome::ScaRequired);
});

it('writes exactly one attempt row per recorded outcome, including blocked', function () {
    $service = armedConfig();

    $service->recordAttempt('tenant-x', 'usd', 'autoreload:tenant-x:usd:1', AutoReloadOutcome::Blocked, errorCode: 'cooldown');

    expect(AutoReloadAttempt::query()->count())->toBe(1);
    $attempt = AutoReloadAttempt::query()->first();
    expect($attempt->outcome)->toBe('blocked')
        ->and($attempt->stripe_error_code)->toBe('cooldown')
        ->and($attempt->caused_disable)->toBeFalse();
});

it('resets the consecutive-failure counter on a success', function () {
    $service = armedConfig();
    configFor()->update(['consecutive_failures' => 2, 'disabled_reason' => null]);

    $service->recordAttempt('tenant-x', 'usd', 'r', AutoReloadOutcome::Succeeded, amount: 25.0, providerRef: 'pi_ok');

    expect(configFor()->consecutive_failures)->toBe(0);
});

it('bumps the counter on a decline and auto-disables at N=3 consecutive', function () {
    $service = armedConfig();

    $service->recordAttempt('tenant-x', 'usd', 'r1', AutoReloadOutcome::Declined, errorCode: 'card_declined');
    expect(configFor()->consecutive_failures)->toBe(1)
        ->and(configFor()->disabled_reason)->toBeNull();

    $service->recordAttempt('tenant-x', 'usd', 'r2', AutoReloadOutcome::Declined, errorCode: 'card_declined');
    expect(configFor()->consecutive_failures)->toBe(2)
        ->and(configFor()->disabled_reason)->toBeNull();

    $attempt = $service->recordAttempt('tenant-x', 'usd', 'r3', AutoReloadOutcome::Declined, errorCode: 'card_declined');
    expect(configFor()->consecutive_failures)->toBe(3)
        ->and(configFor()->disabled_reason)->toBe('repeated_failure')
        ->and($attempt->caused_disable)->toBeTrue();
});

it('disables on the first SCA occurrence', function () {
    $service = armedConfig();

    $attempt = $service->recordAttempt('tenant-x', 'usd', 'r', AutoReloadOutcome::ScaRequired, errorCode: 'authentication_required');

    expect(configFor()->disabled_reason)->toBe('sca_required')
        ->and($attempt->caused_disable)->toBeTrue();
});

it('a success after declines re-arms the config (reset-on-success)', function () {
    $service = armedConfig();
    $service->recordAttempt('tenant-x', 'usd', 'r1', AutoReloadOutcome::Declined, errorCode: 'do_not_honor');
    $service->recordAttempt('tenant-x', 'usd', 'r2', AutoReloadOutcome::Declined, errorCode: 'do_not_honor');
    expect(configFor()->consecutive_failures)->toBe(2);

    $service->recordAttempt('tenant-x', 'usd', 'r3', AutoReloadOutcome::Succeeded, amount: 25.0);

    expect(configFor()->consecutive_failures)->toBe(0)
        ->and(configFor()->disabled_reason)->toBeNull();
});

it('does not bump the counter or disable on a transient error', function () {
    $service = armedConfig();
    configFor()->update(['consecutive_failures' => 1]);

    $attempt = $service->recordAttempt('tenant-x', 'usd', 'r', AutoReloadOutcome::TransientError, errorCode: 'rate_limit');

    expect(configFor()->consecutive_failures)->toBe(1)
        ->and(configFor()->disabled_reason)->toBeNull()
        ->and($attempt->outcome)->toBe('transient_error')
        ->and($attempt->caused_disable)->toBeFalse();
});
