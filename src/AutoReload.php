<?php

namespace Rushing\Commerce;

use Rushing\Commerce\Contracts\PaymentMethodResolver;
use Rushing\Commerce\Data\EffectiveAutoReloadConfig;
use Rushing\Commerce\Models\AutoReloadConfig;

/**
 * The auto-reload write/read/decision surface — a {@see Wallets}-peer over the
 * party-neutral {@see AutoReloadConfig} primitive. It owns the *money decision and
 * lifecycle policy* (threshold, guardrail clamps, the reload decision, the
 * failure counters); the host owns *orchestration and payment-method identity*
 * (ADR-0131). configure/disable/effectiveConfig are here; the load-bearing
 * shouldReload() decision and the failure lifecycle land alongside them.
 *
 * The payment-method resolver is optional: unbound (host-less), a config simply has
 * no chargeable card (has_payment_method = false) and can be read/written but never
 * fires — exactly the degraded state a host binds a real resolver to lift.
 */
class AutoReload
{
    public function __construct(private ?PaymentMethodResolver $resolver = null) {}

    /**
     * Upsert a party's config for a unit. Only the supplied attributes are written,
     * so a partial update (e.g. just flipping enabled) leaves the rest intact.
     *
     * @param  array<string, mixed>  $attrs
     */
    public function configure(string $party, string $unit, array $attrs): AutoReloadConfig
    {
        $allowed = [
            'enabled',
            'threshold_usd',
            'amount_mode',
            'reload_amount_usd',
            'target_usd',
            'cooldown_seconds',
            'max_reloads_per_period',
            'max_spend_per_period_usd',
            'max_per_reload_usd',
            'period_days',
            'payment_method_source',
            'disabled_reason',
            'consecutive_failures',
        ];

        $values = array_intersect_key($attrs, array_flip($allowed));

        return AutoReloadConfig::updateOrCreate(
            ['party_id' => $party, 'unit' => $unit],
            $values,
        );
    }

    /**
     * Degrade a config: record why it stopped firing without erasing tenant intent.
     * enabled stays as the tenant set it (02 branch-6 / 05 decision-6) so the derived
     * status distinguishes a suspended/needs-card config from a deliberate off, and a
     * re-arm just clears disabled_reason.
     */
    public function disable(string $party, string $unit, string $reason): void
    {
        AutoReloadConfig::query()
            ->where('party_id', $party)
            ->where('unit', $unit)
            ->update(['disabled_reason' => $reason]);
    }

    /**
     * The effective (clamped) config for a party+unit, or null when none exists.
     * Fills defaults and enforces the engine safety clamps so downstream policy
     * (shouldReload) reads concrete guardrails; keeps the raw values for the UI hint.
     */
    public function effectiveConfig(string $party, string $unit): ?EffectiveAutoReloadConfig
    {
        $config = AutoReloadConfig::query()
            ->where('party_id', $party)
            ->where('unit', $unit)
            ->first();

        if ($config === null) {
            return null;
        }

        $defaults = (array) config('commerce.autoreload.defaults', []);
        $policy = (array) config('commerce.autoreload.policy', []);

        $minCooldown = (int) ($policy['min_cooldown_seconds'] ?? 60);
        $defaultCooldown = (int) ($defaults['cooldown_seconds'] ?? 300);
        $spendCeiling = (float) ($policy['max_spend_ceiling_usd'] ?? 500);
        $reloadsCeiling = (int) ($policy['max_reloads_ceiling'] ?? 30);
        $perReloadCeiling = (float) ($policy['max_per_reload_ceiling_usd'] ?? 200);

        // Cooldown floors at the policy minimum; a null value takes the host default.
        $effectiveCooldown = max($config->cooldown_seconds ?? $defaultCooldown, $minCooldown);

        // The three ceilings clamp downward; a null tenant value takes the policy ceiling.
        $effectiveReloads = min($config->max_reloads_per_period ?? $reloadsCeiling, $reloadsCeiling);
        $effectiveSpend = min($config->max_spend_per_period_usd ?? $spendCeiling, $spendCeiling);
        $effectivePerReload = min($config->max_per_reload_usd ?? $perReloadCeiling, $perReloadCeiling);

        return new EffectiveAutoReloadConfig(
            party: $party,
            unit: $unit,
            enabled: $config->enabled,
            thresholdUsd: (float) $config->threshold_usd,
            amountMode: $config->amount_mode,
            reloadAmountUsd: $config->reload_amount_usd,
            targetUsd: $config->target_usd,
            cooldownSeconds: $effectiveCooldown,
            maxReloadsPerPeriod: $effectiveReloads,
            maxSpendPerPeriodUsd: $effectiveSpend,
            maxPerReloadUsd: $effectivePerReload,
            periodDays: (int) ($config->period_days ?? ($defaults['period_days'] ?? 30)),
            rawCooldownSeconds: $config->cooldown_seconds,
            rawMaxReloadsPerPeriod: $config->max_reloads_per_period,
            rawMaxSpendPerPeriodUsd: $config->max_spend_per_period_usd,
            rawMaxPerReloadUsd: $config->max_per_reload_usd,
            hasPaymentMethod: $this->resolver?->has($party, $unit) ?? false,
            paymentMethodSource: $config->payment_method_source,
            disabledReason: $config->disabled_reason,
            consecutiveFailures: $config->consecutive_failures,
        );
    }
}
