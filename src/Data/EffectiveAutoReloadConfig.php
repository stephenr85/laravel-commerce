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
class EffectiveAutoReloadConfig
{
    public function __construct(
        public string $party,
        public string $unit,
        public bool $enabled,
        public float $thresholdUsd,
        public string $amountMode,          // fixed | to_target
        public ?float $reloadAmountUsd,
        public ?float $targetUsd,
        // Effective (clamped) guardrails — always concrete, ready for shouldReload().
        public int $cooldownSeconds,
        public int $maxReloadsPerPeriod,
        public float $maxSpendPerPeriodUsd,
        public float $maxPerReloadUsd,
        public int $periodDays,
        // Raw (as configured) guardrails — null when unset; for the clamp hint.
        public ?int $rawCooldownSeconds,
        public ?int $rawMaxReloadsPerPeriod,
        public ?float $rawMaxSpendPerPeriodUsd,
        public ?float $rawMaxPerReloadUsd,
        public bool $hasPaymentMethod,
        public ?string $paymentMethodSource,
        public ?string $disabledReason,
        public int $consecutiveFailures,
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
