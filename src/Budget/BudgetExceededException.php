<?php

namespace Rushing\Commerce\Budget;

use RuntimeException;
use Rushing\Commerce\Data\BudgetVerdict;

/**
 * Thrown when spend would proceed past a billing party's cap. Carries the same
 * {@see BudgetVerdict} a client would inspect, so the deny body has one source of
 * truth wherever the gate was crossed.
 */
class BudgetExceededException extends RuntimeException
{
    public function __construct(public BudgetVerdict $verdict)
    {
        parent::__construct('The spending cap for this billing period has been reached.');
    }
}
