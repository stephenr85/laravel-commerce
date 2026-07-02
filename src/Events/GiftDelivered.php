<?php

declare(strict_types=1);

namespace Rushing\Commerce\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Rushing\Commerce\Models\Redemption;

final class GiftDelivered
{
    use Dispatchable;

    public function __construct(public Redemption $redemption) {}
}
