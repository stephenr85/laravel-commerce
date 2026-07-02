<?php

declare(strict_types=1);

namespace Rushing\Commerce\Enums;

enum Cadence: string
{
    case OneTime = 'one_time';
    case Recurring = 'recurring';
}
