<?php

declare(strict_types=1);

namespace Rushing\Commerce\Budget;

/**
 * A {@see BudgetSource}'s read of a billing party's position: the ceiling, the
 * live spend, and the soft-warn threshold — enough for the {@see BudgetGate} to
 * derive a full verdict (allow/deny, warn, remaining) without a second query. A
 * null cap means uncapped. Amounts are unit-agnostic (USD, tokens, generations) —
 * cap and spend just have to share a unit.
 */
final class BudgetAssessment
{
    public function __construct(
        public readonly ?float $cap,
        public readonly float $spend,
        public readonly float $warnFraction,
    ) {}

    public function uncapped(): bool
    {
        return $this->cap === null;
    }

    public function remaining(): ?float
    {
        return $this->cap === null ? null : $this->cap - $this->spend;
    }

    /** True once spend has reached the cap — the hard stop. */
    public function exceeded(): bool
    {
        return $this->cap !== null && $this->spend >= $this->cap;
    }

    /** Allowed but past the soft-warn threshold — a heads-up, not a block. */
    public function warning(): bool
    {
        return $this->cap !== null
            && ! $this->exceeded()
            && $this->spend >= $this->cap * $this->warnFraction;
    }

    /** Fraction of the cap consumed (0–1+), or null when uncapped / cap is zero. */
    public function fractionUsed(): ?float
    {
        if ($this->cap === null || $this->cap <= 0.0) {
            return null;
        }

        return $this->spend / $this->cap;
    }
}
