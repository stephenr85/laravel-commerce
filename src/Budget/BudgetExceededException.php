<?php

declare(strict_types=1);

namespace Rushing\Commerce\Budget;

use RuntimeException;
use Rushing\Commerce\Data\BudgetVerdict;

/**
 * Thrown when spend would proceed past a billing party's cap. Carries the same
 * {@see BudgetVerdict} a client would inspect, so the deny body has one source of
 * truth wherever the gate was crossed.
 */
final class BudgetExceededException extends RuntimeException
{
    public function __construct(public readonly BudgetVerdict $verdict)
    {
        parent::__construct('The spending cap for this billing period has been reached.');
    }
}
