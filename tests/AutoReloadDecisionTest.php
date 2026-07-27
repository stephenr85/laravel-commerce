<?php

use Illuminate\Support\Carbon;
use Rushing\Commerce\AutoReload;
use Rushing\Commerce\Contracts\PaymentMethodResolver;
use Rushing\Commerce\Contracts\UsageMeter;
use Rushing\Commerce\Data\PaymentMethodRef;
use Rushing\Commerce\Models\AutoReloadAttempt;
use Rushing\Commerce\Wallets;

/** A meter reporting a fixed debited amount, so a test can pin the live balance. */
function meterDebiting(float $debited): UsageMeter
{
    return new class($debited) implements UsageMeter
    {
        public function __construct(private float $debited) {}

        public function debitedFor(string $partyId, string $unit): float
        {
            return $this->debited;
        }
    };
}

function resolverWith(bool $has): PaymentMethodResolver
{
    return new class($has) implements PaymentMethodResolver
    {
        public function __construct(private bool $has) {}

        public function resolve(string $party, string $unit): ?PaymentMethodRef
        {
            return $this->has ? new PaymentMethodRef('cus_x', 'pm_x', 'setup_intent') : null;
        }

        public function has(string $party, string $unit): bool
        {
            return $this->has;
        }
    };
}

/** Build the service with a pinned balance (credited − debited). */
function autoReloadWithBalance(float $credited, float $debited = 0.0): AutoReload
{
    if ($credited > 0.0) {
        app(Wallets::class)->topUp('tenant-x', 'usd', $credited, reason: 'seed:'.uniqid());
    }

    return new AutoReload(resolver: resolverWith(true), meter: meterDebiting($debited));
}

function recordSucceededAttempt(string $reason, float $amount, ?Carbon $at = null): void
{
    AutoReloadAttempt::create([
        'party_id' => 'tenant-x',
        'unit' => 'usd',
        'reason' => $reason,
        'outcome' => 'succeeded',
        'amount_usd' => $amount,
        'created_at' => $at ?? Carbon::now(),
    ]);
}

afterEach(fn () => Carbon::setTestNow());

it('charges a fixed amount when the balance is at or below the threshold', function () {
    $service = autoReloadWithBalance(credited: 2.0);
    $service->configure('tenant-x', 'usd', [
        'enabled' => true, 'threshold_usd' => 5.0, 'amount_mode' => 'fixed', 'reload_amount_usd' => 25.0,
    ]);

    $decision = $service->shouldReload('tenant-x', 'usd');

    expect($decision->shouldReload)->toBeTrue()
        ->and($decision->amount)->toBe(25.0)
        ->and($decision->blockedBy)->toBeNull()
        ->and($decision->reason)->toMatch('/^autoreload:tenant-x:usd:\d+$/');
});

it('blocks above the threshold', function () {
    $service = autoReloadWithBalance(credited: 50.0);
    $service->configure('tenant-x', 'usd', ['enabled' => true, 'threshold_usd' => 5.0, 'reload_amount_usd' => 25.0]);

    $decision = $service->shouldReload('tenant-x', 'usd');

    expect($decision->shouldReload)->toBeFalse()
        ->and($decision->blockedBy)->toBe('above_threshold');
});

it('blocks a disabled (intent-off) config', function () {
    $service = autoReloadWithBalance(credited: 1.0);
    $service->configure('tenant-x', 'usd', ['enabled' => false, 'threshold_usd' => 5.0, 'reload_amount_usd' => 25.0]);

    expect($service->shouldReload('tenant-x', 'usd')->blockedBy)->toBe('disabled');
});

it('blocks a terminally-suspended config (disabled_reason set)', function () {
    $service = autoReloadWithBalance(credited: 1.0);
    $service->configure('tenant-x', 'usd', ['enabled' => true, 'threshold_usd' => 5.0, 'reload_amount_usd' => 25.0]);
    $service->disable('tenant-x', 'usd', 'repeated_failure');

    expect($service->shouldReload('tenant-x', 'usd')->blockedBy)->toBe('disabled');
});

it('computes a to_target amount from the current balance, clamped by the per-reload cap', function () {
    $service = autoReloadWithBalance(credited: 10.0);
    $service->configure('tenant-x', 'usd', [
        'enabled' => true, 'threshold_usd' => 20.0, 'amount_mode' => 'to_target', 'target_usd' => 100.0,
        'max_per_reload_usd' => 50.0,
    ]);

    // target 100 − balance 10 = 90, clamped to the 50 per-reload cap.
    expect($service->shouldReload('tenant-x', 'usd')->amount)->toBe(50.0);
});

it('blocks on cooldown when a reload already succeeded in this window', function () {
    Carbon::setTestNow('2026-07-27 12:00:00');
    $service = autoReloadWithBalance(credited: 1.0);
    $service->configure('tenant-x', 'usd', ['enabled' => true, 'threshold_usd' => 5.0, 'reload_amount_usd' => 25.0]);

    // A succeeded attempt with the *current* window's reason.
    $reason = $service->reasonFor('tenant-x', 'usd', 300);
    recordSucceededAttempt($reason, 25.0);

    expect($service->shouldReload('tenant-x', 'usd')->blockedBy)->toBe('cooldown');
});

it('blocks on the per-period reload count ceiling', function () {
    $service = autoReloadWithBalance(credited: 1.0);
    $service->configure('tenant-x', 'usd', [
        'enabled' => true, 'threshold_usd' => 5.0, 'reload_amount_usd' => 25.0, 'max_reloads_per_period' => 2,
        'cooldown_seconds' => 60,
    ]);

    // Two succeeded attempts in an *earlier* window (so cooldown doesn't fire first).
    recordSucceededAttempt('autoreload:tenant-x:usd:1', 25.0, Carbon::now()->subDay());
    recordSucceededAttempt('autoreload:tenant-x:usd:2', 25.0, Carbon::now()->subHour());

    expect($service->shouldReload('tenant-x', 'usd')->blockedBy)->toBe('period_count');
});

it('blocks on the per-period spend ceiling', function () {
    $service = autoReloadWithBalance(credited: 1.0);
    $service->configure('tenant-x', 'usd', [
        'enabled' => true, 'threshold_usd' => 5.0, 'reload_amount_usd' => 100.0,
        'max_spend_per_period_usd' => 120.0, 'cooldown_seconds' => 60,
    ]);

    // 50 already spent this period; +100 would breach the 120 ceiling.
    recordSucceededAttempt('autoreload:tenant-x:usd:1', 50.0, Carbon::now()->subDay());

    expect($service->shouldReload('tenant-x', 'usd')->blockedBy)->toBe('period_spend');
});

it('computes the same reason for two calls inside one cooldown window', function () {
    Carbon::setTestNow('2026-07-27 12:00:00');
    $service = new AutoReload;

    expect($service->reasonFor('tenant-x', 'usd', 300))
        ->toBe($service->reasonFor('tenant-x', 'usd', 300));
});
