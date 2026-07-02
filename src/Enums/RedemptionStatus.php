<?php

declare(strict_types=1);

namespace Rushing\Commerce\Enums;

enum RedemptionStatus: string
{
    case Issued = 'issued';
    case Delivered = 'delivered';
    case Redeemed = 'redeemed';
    case Expired = 'expired';
}
