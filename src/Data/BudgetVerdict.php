<?php

declare(strict_types=1);

namespace Rushing\Commerce\Data;

use Rushing\Commerce\Budget\BudgetAssessment;
use Rushing\LaravelDataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * The neutral verdict a client renders uniformly: allow/deny, warn state, and the
 * period position. Host-specific affordances (a raise-cap / upgrade offer built
 * from a Plan ladder) are added downstream — the engine keeps no catalog vocabulary
 * on the verdict.
 */
class BudgetVerdict extends Data implements SchemaIdentity
{
    public function __construct(
        public bool $allowed,
        public bool $uncapped,
        public bool $warning,
        public float $spend,
        public ?float $cap,
        public ?float $remaining,
        public ?float $fractionUsed,
    ) {}

    public static function fromAssessment(BudgetAssessment $assessment): self
    {
        if ($assessment->uncapped()) {
            return new self(
                allowed: true,
                uncapped: true,
                warning: false,
                spend: round($assessment->spend, 6),
                cap: null,
                remaining: null,
                fractionUsed: null,
            );
        }

        return new self(
            allowed: ! $assessment->exceeded(),
            uncapped: false,
            warning: $assessment->warning(),
            spend: round($assessment->spend, 6),
            cap: $assessment->cap,
            remaining: round((float) $assessment->remaining(), 6),
            fractionUsed: $assessment->fractionUsed(),
        );
    }

    public static function uncapped(float $spend = 0.0): self
    {
        return new self(true, true, false, round($spend, 6), null, null, null);
    }

    public static function schemaName(): string
    {
        return 'commerce/budget-verdict';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
