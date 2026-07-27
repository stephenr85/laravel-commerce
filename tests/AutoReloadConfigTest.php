<?php

use Rushing\Commerce\AutoReload;
use Rushing\Commerce\Contracts\PaymentMethodResolver;
use Rushing\Commerce\Data\PaymentMethodRef;
use Rushing\Commerce\Models\AutoReloadConfig;

/** A resolver that reports a card present/absent by a fixed flag, for config tests. */
function fakeResolver(bool $has): PaymentMethodResolver
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

it('creates the auto_reload_configs table honoring the config table name', function () {
    expect(Schema::hasTable('commerce_auto_reload_configs'))->toBeTrue();
    expect(Schema::hasColumns('commerce_auto_reload_configs', [
        'party_id', 'unit', 'enabled', 'threshold_usd', 'amount_mode',
        'reload_amount_usd', 'target_usd', 'cooldown_seconds', 'max_reloads_per_period',
        'max_spend_per_period_usd', 'max_per_reload_usd', 'period_days',
        'consecutive_failures', 'disabled_reason', 'payment_method_source',
    ]))->toBeTrue();
});

it('does not store a payment_method_ref column (ADR-0131)', function () {
    expect(Schema::hasColumn('commerce_auto_reload_configs', 'payment_method_ref'))->toBeFalse();
});

it('upserts a config on (party, unit)', function () {
    $service = new AutoReload;

    $service->configure('tenant-x', 'usd', ['enabled' => true, 'threshold_usd' => 5.0, 'reload_amount_usd' => 25.0]);
    $service->configure('tenant-x', 'usd', ['threshold_usd' => 10.0]);

    expect(AutoReloadConfig::query()->where('party_id', 'tenant-x')->count())->toBe(1);

    $config = AutoReloadConfig::query()->where('party_id', 'tenant-x')->first();
    expect($config->threshold_usd)->toBe(10.0)
        ->and($config->enabled)->toBeTrue()         // partial update leaves the rest intact
        ->and($config->reload_amount_usd)->toBe(25.0);
});

it('enforces unique(party_id, unit)', function () {
    $service = new AutoReload;
    $service->configure('tenant-x', 'usd', ['threshold_usd' => 5.0]);
    $service->configure('tenant-x', 'tokens', ['threshold_usd' => 5.0]);

    expect(AutoReloadConfig::query()->where('party_id', 'tenant-x')->count())->toBe(2);
});

it('disable() records the reason but preserves tenant intent (enabled stays)', function () {
    $service = new AutoReload;
    $service->configure('tenant-x', 'usd', ['enabled' => true, 'threshold_usd' => 5.0]);

    $service->disable('tenant-x', 'usd', 'repeated_failure');

    $config = AutoReloadConfig::query()->where('party_id', 'tenant-x')->first();
    expect($config->enabled)->toBeTrue()
        ->and($config->disabled_reason)->toBe('repeated_failure');
});

it('effectiveConfig() is null when no config row exists', function () {
    expect((new AutoReload)->effectiveConfig('nobody', 'usd'))->toBeNull();
});

it('effectiveConfig() clamps over-policy guardrails and exposes the raw values', function () {
    $service = new AutoReload(fakeResolver(true));
    $service->configure('tenant-x', 'usd', [
        'enabled' => true,
        'threshold_usd' => 5.0,
        'reload_amount_usd' => 25.0,
        'cooldown_seconds' => 10,                 // below the 60s floor
        'max_reloads_per_period' => 9999,          // above the 30 ceiling
        'max_spend_per_period_usd' => 100000.0,    // above the 500 ceiling
        'max_per_reload_usd' => 100000.0,          // above the 200 ceiling
    ]);

    $effective = $service->effectiveConfig('tenant-x', 'usd');

    expect($effective->cooldownSeconds)->toBe(60)
        ->and($effective->maxReloadsPerPeriod)->toBe(30)
        ->and($effective->maxSpendPerPeriodUsd)->toBe(500.0)
        ->and($effective->maxPerReloadUsd)->toBe(200.0)
        // raw values survive for the "clamped to $X" hint
        ->and($effective->rawCooldownSeconds)->toBe(10)
        ->and($effective->rawMaxReloadsPerPeriod)->toBe(9999)
        ->and($effective->rawMaxSpendPerPeriodUsd)->toBe(100000.0)
        ->and($effective->rawMaxPerReloadUsd)->toBe(100000.0);
});

it('effectiveConfig() fills defaults for unset guardrails', function () {
    $service = new AutoReload(fakeResolver(true));
    $service->configure('tenant-x', 'usd', ['enabled' => true, 'threshold_usd' => 5.0]);

    $effective = $service->effectiveConfig('tenant-x', 'usd');

    expect($effective->cooldownSeconds)->toBe(300)           // default
        ->and($effective->maxReloadsPerPeriod)->toBe(30)      // ceiling when unset
        ->and($effective->maxSpendPerPeriodUsd)->toBe(500.0)
        ->and($effective->maxPerReloadUsd)->toBe(200.0)
        ->and($effective->periodDays)->toBe(30)
        ->and($effective->rawCooldownSeconds)->toBeNull();
});

it('derives status from enabled + has_payment_method + disabled_reason', function () {
    // off — tenant intent off
    $svc = new AutoReload(fakeResolver(true));
    $svc->configure('p1', 'usd', ['enabled' => false, 'threshold_usd' => 5.0]);
    expect($svc->effectiveConfig('p1', 'usd')->status())->toBe('off');

    // needs_payment_method — armed but no card
    $svcNoCard = new AutoReload(fakeResolver(false));
    $svcNoCard->configure('p2', 'usd', ['enabled' => true, 'threshold_usd' => 5.0]);
    expect($svcNoCard->effectiveConfig('p2', 'usd')->status())->toBe('needs_payment_method');

    // suspended — armed, has card, but terminally disabled
    $svc->configure('p3', 'usd', ['enabled' => true, 'threshold_usd' => 5.0]);
    $svc->disable('p3', 'usd', 'sca_required');
    expect($svc->effectiveConfig('p3', 'usd')->status())->toBe('suspended');

    // active — armed, has card, no failure
    $svc->configure('p4', 'usd', ['enabled' => true, 'threshold_usd' => 5.0]);
    expect($svc->effectiveConfig('p4', 'usd')->status())->toBe('active');
});
