<?php

declare(strict_types=1);

namespace Rushing\Commerce\Budget;

use Rushing\Commerce\Credit;

/**
 * The budget position the {@see BudgetGate} reads before allowing spend. A seam,
 * not a raw number: a postpaid spending cap implements it as `cap` vs `live period
 * spend`, and a prepaid {@see Credit} implements the same
 * signature as `credited` vs `debited` — so prepaid is an additional source, not a
 * rewrite of the gate.
 *
 * The source is bound to its billing party at construction; `assess()` takes no
 * argument, keeping host party vocabulary (Tenant/Merchant) out of the engine.
 */
interface BudgetSource
{
    public function assess(): BudgetAssessment;
}
