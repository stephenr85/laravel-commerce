<?php

declare(strict_types=1);

namespace Rushing\Commerce\Enums;

enum DiscountKind: string
{
    case Percent = 'percent';
    case Amount = 'amount';
}
