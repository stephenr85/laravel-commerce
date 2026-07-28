<?php

use Illuminate\Support\Carbon;
use Rushing\Commerce\AutoReload;
use Rushing\Commerce\Data\AutoReloadPolicyClamps;
use Rushing\Commerce\Models\AutoReloadAttempt;

afterEach(fn () => Carbon::setTestNow());

function recordAttempt(string $party, string $outcome, ?float $amount, Carbon $at): void
{
    AutoReloadAttempt::create([
        'party_id' => $party,
        'unit' => 'usd',
        'reason' => 'autoreload:'.$party.':usd:'.$at->getTimestamp(),
        'outcome' => $outcome,
        'amount_usd' => $amount,
        'created_at' => $at,
    ]);
}

it('reads a party\'s recent attempts newest-first, scoped to party+unit and limited', function () {
    $service = new AutoReload;

    recordAttempt('tenant-x', 'succeeded', 25.0, Carbon::parse('2026-07-01 10:00:00'));
    recordAttempt('tenant-x', 'declined', null, Carbon::parse('2026-07-02 10:00:00'));
    recordAttempt('tenant-x', 'succeeded', 50.0, Carbon::parse('2026-07-03 10:00:00'));
    // Noise the read must exclude: a different party and a different unit.
    recordAttempt('other-party', 'succeeded', 99.0, Carbon::parse('2026-07-04 10:00:00'));
    AutoReloadAttempt::create([
        'party_id' => 'tenant-x', 'unit' => 'eur', 'reason' => 'x',
        'outcome' => 'succeeded', 'amount_usd' => 5.0, 'created_at' => Carbon::parse('2026-07-05 10:00:00'),
    ]);

    $attempts = $service->recentAttempts('tenant-x', 'usd', 20);

    expect($attempts)->toHaveCount(3)
        ->and($attempts->pluck('outcome')->all())->toBe(['succeeded', 'declined', 'succeeded'])
        ->and($attempts->first()->amount_usd)->toEqual(50.0); // newest first
});

it('honours the limit on recent attempts', function () {
    $service = new AutoReload;

    foreach (range(1, 5) as $i) {
        recordAttempt('tenant-x', 'succeeded', (float) $i, Carbon::parse('2026-07-0'.$i.' 10:00:00'));
    }

    expect($service->recentAttempts('tenant-x', 'usd', 2))->toHaveCount(2);
});

it('exposes the engine policy clamps from config', function () {
    $clamps = (new AutoReload)->policyClamps();

    expect($clamps)->toBeInstanceOf(AutoReloadPolicyClamps::class)
        ->and($clamps->minCooldownSeconds)->toBe(60)
        ->and($clamps->maxReloadsCeiling)->toBe(30)
        ->and($clamps->maxSpendCeilingUsd)->toBe(500.0)
        ->and($clamps->maxPerReloadCeilingUsd)->toBe(200.0)
        ->and($clamps->defaultCooldownSeconds)->toBe(300)
        ->and($clamps->disableAfterConsecutiveFailures)->toBe(3);
});

it('falls back to shipped ceilings when the policy config is absent', function () {
    config(['commerce.autoreload.policy' => [], 'commerce.autoreload.defaults' => []]);

    $clamps = AutoReloadPolicyClamps::fromConfig();

    expect($clamps->minCooldownSeconds)->toBe(60)
        ->and($clamps->maxSpendCeilingUsd)->toBe(500.0)
        ->and($clamps->defaultCooldownSeconds)->toBe(300);
});
