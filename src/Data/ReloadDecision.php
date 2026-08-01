<?php

namespace Rushing\Commerce\Data;

use Rushing\Commerce\AutoReload;

/**
 * The engine's answer to "should this party's Wallet be topped up right now, and
 * how much?" — the load-bearing money decision {@see AutoReload::shouldReload()}
 * returns. When shouldReload is false, blockedBy names why (above_threshold |
 * disabled | cooldown | per_reload | period_count | period_spend); the reason string
 * (autoreload:{party}:{unit}:{window}) always rides so an orchestrator locks and
 * charges under the exact key the engine computed — the app never re-derives it.
 */
class ReloadDecision
{
    public function __construct(
        public bool $shouldReload,
        public float $amount,
        public string $reason,
        public ?string $blockedBy = null,
    ) {}

    public static function charge(float $amount, string $reason): self
    {
        return new self(true, $amount, $reason);
    }

    public static function blocked(string $blockedBy, string $reason, float $amount = 0.0): self
    {
        return new self(false, $amount, $reason, $blockedBy);
    }
}
