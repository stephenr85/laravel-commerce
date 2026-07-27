<?php

namespace Rushing\Commerce\Data;

/**
 * A party's auto-reload config after the engine safety clamps are applied —
 * effective = clamp(config, policy). Every guardrail is resolved to a concrete
 * effective value (defaults filled, policy ceilings/floors enforced) so the money
 * decision never re-derives it; the raw (as-configured) values ride alongside so a
 * host UI can render the "clamped to $X / platform max $X" hint. hasPaymentMethod is
 * delegated to the host-bound resolver; status is derived, never stored.
 */
final class EffectiveAutoReloadConfig
{
    public function __construct(
        public readonly string $party,
        public readonly string $unit,
        public readonly bool $enabled,
        public readonly float $thresholdUsd,
        public readonly string $amountMode,          // fixed | to_target
        public readonly ?float $reloadAmountUsd,
        public readonly ?float $targetUsd,
        // Effective (clamped) guardrails — always concrete, ready for shouldReload().
        public readonly int $cooldownSeconds,
        public readonly int $maxReloadsPerPeriod,
        public readonly float $maxSpendPerPeriodUsd,
        public readonly float $maxPerReloadUsd,
        public readonly int $periodDays,
        // Raw (as configured) guardrails — null when unset; for the clamp hint.
        public readonly ?int $rawCooldownSeconds,
        public readonly ?int $rawMaxReloadsPerPeriod,
        public readonly ?float $rawMaxSpendPerPeriodUsd,
        public readonly ?float $rawMaxPerReloadUsd,
        public readonly bool $hasPaymentMethod,
        public readonly ?string $paymentMethodSource,
        public readonly ?string $disabledReason,
        public readonly int $consecutiveFailures,
    ) {}

    /**
     * The presentation status a host UI renders: off (tenant intent off), active
     * (armed and chargeable), needs_payment_method (armed but no card), suspended
     * (armed with a card but disabled by a terminal failure). Derived, not stored,
     * so a deliberate off is distinguishable from an engine-broken config.
     */
    public function status(): string
    {
        if (! $this->enabled) {
            return 'off';
        }

        if (! $this->hasPaymentMethod || $this->disabledReason === 'payment_method_unavailable') {
            return 'needs_payment_method';
        }

        if ($this->disabledReason !== null) {
            return 'suspended';
        }

        return 'active';
    }
}
