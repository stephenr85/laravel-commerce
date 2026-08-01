<?php

namespace Rushing\Commerce\Data;

use Rushing\Commerce\AutoReload;

/**
 * The engine's auto-reload safety clamps, resolved once from
 * `commerce.autoreload.{policy,defaults}` — the single engine-owned source of the
 * platform ceilings a tenant value can never weaken (effective = clamp(config,
 * policy)) plus the host default cooldown an unset value falls back to.
 *
 * The engine is the enforcement authority: {@see AutoReload::effectiveConfig()}
 * clamps against these, and {@see AutoReload::policyClamps()} hands the same
 * ceilings to a host UI so it renders the "platform max $X" hint without re-deriving the
 * policy fallback defaults app-side.
 */
class AutoReloadPolicyClamps
{
    public function __construct(
        public int $minCooldownSeconds,
        public int $maxReloadsCeiling,
        public float $maxSpendCeilingUsd,
        public float $maxPerReloadCeilingUsd,
        public int $defaultCooldownSeconds,
        public int $disableAfterConsecutiveFailures,
    ) {}

    /**
     * Read the clamps from config, applying the same fallback defaults the money
     * decision uses — so a host that never publishes the package config still gets the
     * shipped ceilings rather than zeroes.
     */
    public static function fromConfig(): self
    {
        $policy = (array) config('commerce.autoreload.policy', []);
        $defaults = (array) config('commerce.autoreload.defaults', []);

        return new self(
            minCooldownSeconds: (int) ($policy['min_cooldown_seconds'] ?? 60),
            maxReloadsCeiling: (int) ($policy['max_reloads_ceiling'] ?? 30),
            maxSpendCeilingUsd: (float) ($policy['max_spend_ceiling_usd'] ?? 500),
            maxPerReloadCeilingUsd: (float) ($policy['max_per_reload_ceiling_usd'] ?? 200),
            defaultCooldownSeconds: (int) ($defaults['cooldown_seconds'] ?? 300),
            disableAfterConsecutiveFailures: (int) ($policy['disable_after_consecutive_failures'] ?? 3),
        );
    }
}
