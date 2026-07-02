<?php

declare(strict_types=1);

namespace Rushing\Commerce\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Rushing\Commerce\Data\Refund;

final class PaymentRefunded
{
    use Dispatchable;

    public function __construct(public Refund $refund) {}
}
