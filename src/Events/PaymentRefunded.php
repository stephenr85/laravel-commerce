<?php

namespace Rushing\Commerce\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Rushing\Commerce\Data\Refund;

class PaymentRefunded
{
    use Dispatchable;

    public function __construct(public Refund $refund) {}
}
