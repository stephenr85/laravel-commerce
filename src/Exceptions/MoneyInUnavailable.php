<?php

namespace Rushing\Commerce\Exceptions;

use RuntimeException;

/**
 * The `none` rail was asked to collect or manage money (purchase-walkthrough BUY-4). Custody `none` renders no purchase
 * affordance, so reaching this is a defect in the caller, not a payment outcome.
 */
class MoneyInUnavailable extends RuntimeException
{
    public static function make(string $what): self
    {
        return new self("This host collects no money (commerce rail `none`), so it cannot {$what}.");
    }
}
