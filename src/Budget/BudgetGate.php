<?php

namespace Rushing\Commerce\Budget;

use Rushing\Commerce\Data\BudgetVerdict;

/**
 * The pre-flight chokepoint for spend, consulted once before any usage is written
 * so an over-cap run is refused with no partial work. Domain-agnostic: it reads a
 * {@see BudgetSource} and renders a neutral {@see BudgetVerdict}. A null source —
 * spend outside any billing context — is uncapped. The stop is hard; the warn
 * threshold is a heads-up, never a soft-queue.
 */
class BudgetGate
{
    public function allows(?BudgetSource $source): bool
    {
        return $this->inspect($source)->allowed;
    }

    public function inspect(?BudgetSource $source): BudgetVerdict
    {
        if ($source === null) {
            return BudgetVerdict::uncapped();
        }

        return BudgetVerdict::fromAssessment($source->assess());
    }

    public function authorize(?BudgetSource $source): void
    {
        $verdict = $this->inspect($source);

        if (! $verdict->allowed) {
            throw new BudgetExceededException($verdict);
        }
    }
}
