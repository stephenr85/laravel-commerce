<?php

namespace Rushing\Commerce\Enums;

enum DiscountKind: string
{
    case Percent = 'percent';
    case Amount = 'amount';
}
