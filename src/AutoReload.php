<?php

namespace Rushing\Commerce;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Rushing\Commerce\Contracts\PaymentMethodResolver;
use Rushing\Commerce\Contracts\UsageMeter;
use Rushing\Commerce\Data\EffectiveAutoReloadConfig;
use Rushing\Commerce\Data\ReloadDecision;
use Rushing\Commerce\Enums\AutoReloadOutcome;
use Rushing\Commerce\Models\AutoReloadAttempt;
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
    public function __construct(
        private ?PaymentMethodResolver $resolver = null,
        private ?UsageMeter $meter = null,
        private ?Wallets $wallets = null,
    ) {}

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

    /**
     * The load-bearing money decision: given a party's live balance, effective config,
     * and the autoreload: attempt ledger, decide whether to top up now and by how much.
     * Threshold, cooldown, the period ceilings, and the amount (fixed vs to_target) are
     * all computed *here* so an orchestrator never re-derives dollar math — it locks and
     * charges under the returned reason. A guardrail stop returns shouldReload=false with
     * the reason for the block (no charge attempted).
     */
    public function shouldReload(string $party, string $unit): ReloadDecision
    {
        $config = $this->effectiveConfig($party, $unit);
        $reason = $this->reasonFor($party, $unit, $config?->cooldownSeconds ?? 300);

        // A disabled config (tenant intent off) or a terminally-suspended one
        // (disabled_reason set — repeated_failure / sca_required / no card) does not fire.
        if ($config === null || ! $config->enabled || $config->disabledReason !== null) {
            return ReloadDecision::blocked('disabled', $reason);
        }

        $balance = $this->balanceFor($party, $unit);

        // Absolute floor: only reload at or below the configured threshold.
        if ($balance > $config->thresholdUsd) {
            return ReloadDecision::blocked('above_threshold', $reason);
        }

        // Cooldown: a succeeded reload already landed in this window bucket.
        if ($this->succeededInWindow($party, $unit, $reason)) {
            return ReloadDecision::blocked('cooldown', $reason);
        }

        $periodStart = Carbon::now()->subDays($config->periodDays);
        $succeeded = $this->succeededAttemptsSince($party, $unit, $periodStart);

        // Per-period reload count ceiling.
        if ($succeeded->count() >= $config->maxReloadsPerPeriod) {
            return ReloadDecision::blocked('period_count', $reason);
        }

        // Amount: fixed charges the configured amount; to_target tops up to the target
        // from the *current* balance; both clamp to the effective per-reload cap.
        $amount = $config->amountMode === 'to_target'
            ? max(0.0, ($config->targetUsd ?? 0.0) - $balance)
            : ($config->reloadAmountUsd ?? 0.0);

        $amount = min($amount, $config->maxPerReloadUsd);

        // A non-positive charge (misconfigured amount, or already at target) can't top up.
        if ($amount <= 0.0) {
            return ReloadDecision::blocked('per_reload', $reason);
        }

        // Per-period spend ceiling — the running spend plus this charge must fit.
        $periodSpend = (float) $succeeded->sum('amount_usd');
        if ($periodSpend + $amount > $config->maxSpendPerPeriodUsd) {
            return ReloadDecision::blocked('period_spend', $reason);
        }

        return ReloadDecision::charge($amount, $reason);
    }

    /**
     * Record one attempt (success and failure alike) and apply the failure lifecycle
     * — the single engine-owned source behind the config's counters, a host's history
     * surface, notifications, and alerts. Only an instrument Declined bumps the
     * consecutive-failure counter (auto-disabling at the policy threshold as
     * repeated_failure); ScaRequired disables on the first occurrence; a success resets
     * the counter (and clears a stale disabled_reason); TransientError and Blocked touch
     * no counter. Auto-reload is additive — this never restricts generation, it only
     * degrades the config's own firing.
     */
    public function recordAttempt(
        string $party,
        string $unit,
        string $reason,
        AutoReloadOutcome $outcome,
        ?float $amount = null,
        ?string $providerRef = null,
        ?string $errorCode = null,
    ): AutoReloadAttempt {
        $config = AutoReloadConfig::query()
            ->where('party_id', $party)
            ->where('unit', $unit)
            ->first();

        $threshold = (int) config('commerce.autoreload.policy.disable_after_consecutive_failures', 3);
        $counter = $config?->consecutive_failures ?? 0;
        $causedDisable = false;

        switch ($outcome) {
            case AutoReloadOutcome::Succeeded:
                $counter = 0;
                if ($config !== null) {
                    $config->consecutive_failures = 0;
                    $config->disabled_reason = null; // reset-on-success re-arms a working card
                    $config->save();
                }
                break;

            case AutoReloadOutcome::Declined:
                $counter++;
                if ($config !== null) {
                    $config->consecutive_failures = $counter;
                    if ($counter >= $threshold) {
                        $config->disabled_reason = 'repeated_failure';
                        $causedDisable = true;
                    }
                    $config->save();
                }
                break;

            case AutoReloadOutcome::ScaRequired:
                $counter++; // snapshot; SCA disables on the first occurrence regardless
                if ($config !== null) {
                    $config->consecutive_failures = $counter;
                    $config->disabled_reason = 'sca_required';
                    $config->save();
                    $causedDisable = true;
                }
                break;

            case AutoReloadOutcome::TransientError:
            case AutoReloadOutcome::Blocked:
                // Not an instrument failure — audited, but no counter change, no disable.
                break;
        }

        return AutoReloadAttempt::create([
            'party_id' => $party,
            'unit' => $unit,
            'reason' => $reason,
            'outcome' => $outcome->value,
            'stripe_error_code' => $errorCode,
            'amount_usd' => $amount,
            'provider_ref' => $providerRef,
            'consecutive_failures_after' => $counter,
            'caused_disable' => $causedDisable,
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * The reload reason for the current cooldown window: autoreload:{party}:{unit}:{window}
     * where {window} is a floored epoch bucket of cooldown_seconds. Two calls inside one
     * window compute the same reason — the dedup-lock key, the topUpOnce/idempotency seed,
     * and the guardrail-ledger scan key, all in one string. Never persisted.
     */
    public function reasonFor(string $party, string $unit, int $cooldownSeconds): string
    {
        $cooldownSeconds = max(1, $cooldownSeconds);
        $window = intdiv(Carbon::now()->getTimestamp(), $cooldownSeconds);

        return "autoreload:{$party}:{$unit}:{$window}";
    }

    private function balanceFor(string $party, string $unit): float
    {
        $wallets = $this->wallets ?? new Wallets;
        $credited = $wallets->creditedFor($party, $unit);
        $debited = $this->meter?->debitedFor($party, $unit) ?? 0.0;

        return $credited - $debited;
    }

    private function succeededInWindow(string $party, string $unit, string $reason): bool
    {
        return AutoReloadAttempt::query()
            ->where('party_id', $party)
            ->where('unit', $unit)
            ->where('reason', $reason)
            ->where('outcome', 'succeeded')
            ->exists();
    }

    /**
     * The party's succeeded auto-reload attempts within the rolling period, hydrated
     * once so the count and spend ceilings share a single query.
     *
     * @return Collection<int, AutoReloadAttempt>
     */
    private function succeededAttemptsSince(string $party, string $unit, Carbon $since): Collection
    {
        return AutoReloadAttempt::query()
            ->where('party_id', $party)
            ->where('unit', $unit)
            ->where('outcome', 'succeeded')
            ->where('created_at', '>=', $since)
            ->get();
    }
}
